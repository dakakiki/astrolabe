<?php

namespace App\Astrology\Engines;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Astrology\ValueObjects\Houses;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the Swiss Ephemeris command-line program (`swetest`) as a local process.
 *
 * Arguments are built only from numbers and enum values, never from user input,
 * and passed as an array, so no shell ever parses them (docs/spec/06).
 */
class SwissEphemerisEngine implements EphemerisEngine
{
    /**
     * Inside the polar circles Placidus and Koch cannot be drawn; swetest then
     * calculates Porphyry and says so on a line of its own. That is an expected
     * outcome, not a failure: the chart records both systems (docs/spec/11).
     */
    private const HOUSE_FALLBACK = '/^error: House method (.+?) failed, (.+?) calculated instead\.?\s*$/mi';

    private static ?string $version = null;

    private static ?string $ephemerisVersion = null;

    public function __construct(
        private readonly string $binary,
        private readonly string $ephemerisPath,
        private readonly int $timeout = 10,
    ) {}

    public function name(): string
    {
        return 'Swiss Ephemeris';
    }

    public function calculate(ChartRequest $request): ChartResult
    {
        // Name, number, longitude, speed: the name tells house lines from planets.
        $arguments = $this->arguments($request, '-fPpls');

        $houses = $request->wantsHouses();

        if ($houses) {
            $arguments[] = sprintf('-house%.6f,%.6f,%s', $request->longitude, $request->latitude, $request->houseSystem->swissEphemerisCode());
        }

        $rows = $this->rows($this->run($arguments));

        return new ChartResult(
            positions: $this->parsePositions($rows, $request->bodies),
            engineName: $this->name(),
            engineVersion: $this->version(),
            ephemerisVersion: $this->ephemerisVersion(),
            houses: $houses ? $this->parseHouses($rows, $request->houseSystem) : null,
        );
    }

    /**
     * One swetest run with `-n` steps of `-s` days; each line starts with its
     * Julian day ("2461309.00|Saturn|6|12.0059044|-0.0768925").
     */
    public function series(ChartRequest $request, int $steps, float $stepDays = 1.0): array
    {
        $arguments = [
            ...$this->arguments($request, '-fJPpls'),
            '-n'.$steps,
            '-s'.rtrim(rtrim(sprintf('%.6F', $stepDays), '0'), '.'),
        ];

        $output = $this->run($arguments);
        $moments = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $columns = array_map('trim', explode('|', $line));

            if (count($columns) < 5 || ! is_numeric($columns[0]) || ! is_numeric($columns[2])) {
                continue;
            }

            $body = CelestialBody::fromSwissEphemerisNumber((int) $columns[2]);

            if ($body !== null) {
                $moments[$columns[0]][$body->value] = new PlanetPosition($body, (float) $columns[3], (float) $columns[4]);
            }
        }

        if (count($moments) !== $steps) {
            throw new EphemerisException(sprintf('swetest returned %d of %d moments.', count($moments), $steps));
        }

        return array_values(array_map(fn (array $positions) => array_map(
            fn (CelestialBody $body) => $positions[$body->value]
                ?? throw new EphemerisException("swetest returned no position for {$body->value}."),
            $request->bodies,
        ), $moments));
    }

    /**
     * What every run shares: the moment, the bodies, the output format and the
     * zodiac. Houses are added by calculate() alone.
     *
     * @return list<string>
     */
    private function arguments(ChartRequest $request, string $format): array
    {
        $arguments = [
            '-bj'.sprintf('%.8f', $request->julianDayUt),
            '-ut',
            '-p'.implode('', array_map(fn (CelestialBody $body) => $body->swetestLetter(), $request->bodies)),
            $format,
            '-g|',
            '-head',
            '-eswe',
            '-edir'.$this->ephemerisPath,
        ];

        if ($request->zodiacMode === ZodiacMode::Sidereal && $request->ayanamsa !== null) {
            $arguments[] = '-sid'.$request->ayanamsa->swissEphemerisMode();
        }

        return $arguments;
    }

    public function version(): string
    {
        if (self::$version === null) {
            preg_match('/Version:\s*([\d.]+)/', $this->run(['-h'], allowWarnings: true), $match);
            self::$version = $match[1] ?? 'unknown';
        }

        return self::$version;
    }

    public function fingerprint(): string
    {
        return $this->name().' '.$this->version().' '.$this->ephemerisVersion();
    }

    /**
     * The ephemeris files in use with a checksum each, e.g.
     * "semo_18.se1:1f2e3d4c,sepl_18.se1:9a8b7c6d". Replacing a file changes it.
     */
    public function ephemerisVersion(): string
    {
        if (self::$ephemerisVersion === null) {
            $files = glob(rtrim($this->ephemerisPath, '/\\').DIRECTORY_SEPARATOR.'*.se1') ?: [];
            sort($files);

            self::$ephemerisVersion = implode(',', array_map(
                fn (string $file) => basename($file).':'.hash_file('crc32b', $file),
                $files,
            )) ?: 'none';
        }

        return self::$ephemerisVersion;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, bool $allowWarnings = false): string
    {
        if (! is_file($this->binary)) {
            throw new EphemerisException("swetest not found at {$this->binary}.");
        }

        $process = new Process([$this->binary, ...$arguments]);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new EphemerisException("swetest timed out after {$this->timeout} s.");
        }

        $output = $process->getOutput().$process->getErrorOutput();

        if (! $process->isSuccessful()) {
            throw new EphemerisException('swetest failed: '.trim($output));
        }

        // Without its data files swetest silently falls back to the less precise
        // Moshier ephemeris and still exits 0; that must never pass unnoticed.
        // The polar house fallback is the one message that is expected.
        $problems = preg_replace(self::HOUSE_FALLBACK, '', $output);

        if (! $allowWarnings && preg_match('/warning|error|moshier|not found/i', $problems)) {
            throw new EphemerisException('swetest reported a problem: '.trim($output));
        }

        return $output;
    }

    /**
     * Lines like "Sun|0|112.9549256|0.9542723" (name, number, longitude, speed).
     * swetest prints every planet first, then the houses from "house  1" on;
     * house lines are numbered 1–20, so the number alone would be ambiguous.
     *
     * @return array{
     *     planets: list<array{name: string, number: int, longitude: float, speed: float}>,
     *     houses: list<array{name: string, number: int, longitude: float, speed: float}>,
     *     output: string,
     * }
     */
    private function rows(string $output): array
    {
        $planets = [];
        $houses = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $columns = array_map('trim', explode('|', $line));

            if (count($columns) < 4 || ! is_numeric($columns[1]) || ! is_numeric($columns[2]) || ! is_numeric($columns[3])) {
                continue;
            }

            $row = [
                'name' => $columns[0],
                'number' => (int) $columns[1],
                'longitude' => (float) $columns[2],
                'speed' => (float) $columns[3],
            ];

            if ($houses === [] && ! preg_match('/^house\s+1$/i', $row['name'])) {
                $planets[] = $row;
            } else {
                $houses[] = $row;
            }
        }

        return ['planets' => $planets, 'houses' => $houses, 'output' => $output];
    }

    /**
     * @param  array{planets: list<array{name: string, number: int, longitude: float, speed: float}>}  $parsed
     * @param  list<CelestialBody>  $expected
     * @return list<PlanetPosition>
     */
    private function parsePositions(array $parsed, array $expected): array
    {
        $positions = [];

        foreach ($parsed['planets'] as $row) {
            $body = CelestialBody::fromSwissEphemerisNumber($row['number']);

            if ($body !== null) {
                $positions[$body->value] = new PlanetPosition($body, $row['longitude'], $row['speed']);
            }
        }

        $ordered = [];
        foreach ($expected as $body) {
            $ordered[] = $positions[$body->value]
                ?? throw new EphemerisException("swetest returned no position for {$body->value}.");
        }

        return $ordered;
    }

    /**
     * Lines "house  1" … "house 12", then "Ascendant", "MC", "ARMC", "Vertex"
     * and further points this adapter does not use.
     *
     * @param  array{houses: list<array{name: string, number: int, longitude: float, speed: float}>, output: string}  $parsed
     */
    private function parseHouses(array $parsed, HouseSystem $requested): Houses
    {
        $cusps = [];
        $points = [];

        foreach ($parsed['houses'] as $row) {
            if (preg_match('/^house\s+(\d{1,2})$/i', $row['name'], $match)) {
                $cusps[(int) $match[1]] = $row['longitude'];
            } elseif (in_array($row['name'], ['Ascendant', 'MC', 'ARMC', 'Vertex'], true)) {
                $points[$row['name']] = $row['longitude'];
            }
        }

        ksort($cusps);

        if (array_keys($cusps) !== range(1, 12) || count($points) !== 4) {
            throw new EphemerisException('swetest returned incomplete houses: '.trim($parsed['output']));
        }

        return new Houses(
            system: $this->calculatedSystem($parsed['output'], $requested),
            requestedSystem: $requested,
            cusps: array_values($cusps),
            ascendant: $points['Ascendant'],
            midheaven: $points['MC'],
            armc: $points['ARMC'],
            vertex: $points['Vertex'],
        );
    }

    private function calculatedSystem(string $output, HouseSystem $requested): HouseSystem
    {
        if (! preg_match(self::HOUSE_FALLBACK, $output, $match)) {
            return $requested;
        }

        return HouseSystem::fromSwissEphemerisName($match[2])
            ?? throw new EphemerisException("swetest fell back to an unknown house system: {$match[2]}.");
    }
}
