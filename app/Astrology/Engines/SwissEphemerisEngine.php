<?php

namespace App\Astrology\Engines;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\CelestialBody;
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
        $arguments = [
            '-bj'.sprintf('%.8f', $request->julianDayUt),
            '-ut',
            '-p'.implode('', array_map(fn (CelestialBody $body) => $body->swetestLetter(), $request->bodies)),
            '-fpls',
            '-g|',
            '-head',
            '-eswe',
            '-edir'.$this->ephemerisPath,
        ];

        if ($request->zodiacMode === ZodiacMode::Sidereal && $request->ayanamsa !== null) {
            $arguments[] = '-sid'.$request->ayanamsa->swissEphemerisMode();
        }

        $output = $this->run($arguments);

        return new ChartResult(
            positions: $this->parsePositions($output, $request->bodies),
            engineName: $this->name(),
            engineVersion: $this->version(),
            ephemerisVersion: $this->ephemerisVersion(),
        );
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
        if (! $allowWarnings && preg_match('/warning|error|moshier|not found/i', $output)) {
            throw new EphemerisException('swetest reported a problem: '.trim($output));
        }

        return $output;
    }

    /**
     * Lines like "0|112.9549256|0.9542723": planet number, longitude, speed.
     *
     * @param  list<CelestialBody>  $expected
     * @return list<PlanetPosition>
     */
    private function parsePositions(string $output, array $expected): array
    {
        $positions = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $columns = array_map('trim', explode('|', $line));

            if (count($columns) < 3 || ! is_numeric($columns[0]) || ! is_numeric($columns[1]) || ! is_numeric($columns[2])) {
                continue;
            }

            $body = CelestialBody::fromSwissEphemerisNumber((int) $columns[0]);

            if ($body !== null) {
                $positions[$body->value] = new PlanetPosition($body, (float) $columns[1], (float) $columns[2]);
            }
        }

        $ordered = [];
        foreach ($expected as $body) {
            $ordered[] = $positions[$body->value]
                ?? throw new EphemerisException("swetest returned no position for {$body->value}.");
        }

        return $ordered;
    }
}
