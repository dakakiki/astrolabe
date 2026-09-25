<?php

namespace App\Astrology\Engines;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Astrology\ValueObjects\Houses;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;

/**
 * A stand-in for tests and CI (docs/spec/11): deterministic, needs no binary,
 * data files or licence. Positions follow each body's mean motion from J2000 —
 * plausible, never correct. Tests can pin exact positions with fix().
 *
 * Angles come from mean sidereal time and a fixed obliquity; Equal and Whole
 * Sign cusps are exact, every other system is drawn as Porphyry. Inside the
 * polar circles Placidus and Koch fall back to Porphyry, as the real engine does.
 */
class FakeEngine implements EphemerisEngine
{
    /** Mean longitude at J2000.0 and mean daily motion, in degrees. */
    private const MEAN_MOTION = [
        'sun' => [280.460, 0.985647],
        'moon' => [218.316, 13.176396],
        'mercury' => [252.251, 4.092339],
        'venus' => [181.980, 1.602131],
        'mars' => [355.433, 0.524033],
        'jupiter' => [34.351, 0.083091],
        'saturn' => [50.077, 0.033460],
        'uranus' => [314.055, 0.011733],
        'neptune' => [304.349, 0.006000],
        'pluto' => [238.929, 0.003964],
        'chiron' => [251.618, 0.019440],
        'true_node' => [125.045, -0.052954],
        'mean_node' => [125.045, -0.052954],
    ];

    private const J2000 = 2451545.0;

    private const OBLIQUITY = 23.4393;

    /** A fixed ayanamsa, so sidereal charts differ from tropical ones in tests. */
    public const AYANAMSA = 24.0;

    /** @var array<string, array{0: float, 1: float, 2: float|null}> body => [longitude, speed, moving from] */
    private array $fixed = [];

    /** @var array{0: float, 1: float}|null [ascendant, midheaven], tropical */
    private ?array $fixedAngles = null;

    public int $calls = 0;

    public function name(): string
    {
        return 'Fake engine';
    }

    public function version(): string
    {
        return '1';
    }

    public function fingerprint(): string
    {
        return 'fake-1';
    }

    /**
     * Pin a body to a longitude and speed for every following calculation.
     * With `$from` (a Julian day) the body is at that longitude then and moves
     * on at that speed, so a series of moments sees it travel.
     */
    public function fix(CelestialBody $body, float $longitude, float $speed, ?float $from = null): static
    {
        $this->fixed[$body->value] = [$longitude, $speed, $from];

        return $this;
    }

    /**
     * Pin the Ascendant and Midheaven (tropical) for every following calculation.
     */
    public function fixAngles(float $ascendant, float $midheaven): static
    {
        $this->fixedAngles = [$ascendant, $midheaven];

        return $this;
    }

    public function calculate(ChartRequest $request): ChartResult
    {
        $this->calls++;
        $days = $request->julianDayUt - self::J2000;
        $shift = $request->zodiacMode === ZodiacMode::Sidereal ? self::AYANAMSA : 0.0;

        return new ChartResult(
            $this->positions($request, $request->julianDayUt),
            $this->name(),
            $this->version(),
            null,
            $request->wantsHouses() ? $this->houses($request, $days, $shift) : null,
        );
    }

    public function series(ChartRequest $request, int $steps, float $stepDays = 1.0): array
    {
        $this->calls++;

        return array_map(
            fn (int $step) => $this->positions($request, $request->julianDayUt + $step * $stepDays),
            range(0, $steps - 1),
        );
    }

    /**
     * @return list<PlanetPosition>
     */
    private function positions(ChartRequest $request, float $julianDay): array
    {
        $days = $julianDay - self::J2000;
        $shift = $request->zodiacMode === ZodiacMode::Sidereal ? self::AYANAMSA : 0.0;

        return array_map(function (CelestialBody $body) use ($days, $julianDay, $shift) {
            if (isset($this->fixed[$body->value])) {
                [$longitude, $speed, $from] = $this->fixed[$body->value];
                $longitude += $from === null ? 0.0 : $speed * ($julianDay - $from);
            } else {
                [$start, $speed] = self::MEAN_MOTION[$body->value];
                $longitude = $start + $speed * $days;
            }

            return new PlanetPosition($body, self::normalize($longitude - $shift), $speed);
        }, $request->bodies);
    }

    private function houses(ChartRequest $request, float $days, float $shift): Houses
    {
        $armc = self::normalize(280.46061837 + 360.98564736629 * $days + $request->longitude);
        [$ascendant, $midheaven] = $this->fixedAngles ?? [
            self::ascendant($armc, $request->latitude),
            self::normalize(rad2deg(atan2(sin(deg2rad($armc)), cos(deg2rad($armc)) * cos(deg2rad(self::OBLIQUITY))))),
        ];
        $vertex = self::ascendant($armc + 180, $request->latitude >= 0 ? 90 - $request->latitude : -90 - $request->latitude);

        $ascendant = self::normalize($ascendant - $shift);
        $midheaven = self::normalize($midheaven - $shift);

        $system = $request->houseSystem;
        if ($system->failsNearPoles() && abs($request->latitude) >= 90 - self::OBLIQUITY) {
            $system = HouseSystem::Porphyry;
        }

        $cusps = match ($system) {
            HouseSystem::Equal => array_map(fn (int $i) => self::normalize($ascendant + 30 * $i), range(0, 11)),
            HouseSystem::WholeSign => array_map(fn (int $i) => self::normalize(floor($ascendant / 30) * 30 + 30 * $i), range(0, 11)),
            default => self::porphyry($ascendant, $midheaven),
        };

        return new Houses(
            system: $system,
            requestedSystem: $request->houseSystem,
            cusps: $cusps,
            ascendant: $ascendant,
            midheaven: $midheaven,
            armc: $armc,
            vertex: self::normalize($vertex - $shift),
        );
    }

    private static function ascendant(float $armc, float $latitude): float
    {
        $theta = deg2rad($armc);
        $obliquity = deg2rad(self::OBLIQUITY);

        return self::normalize(rad2deg(atan2(
            cos($theta),
            -(sin($theta) * cos($obliquity) + tan(deg2rad($latitude)) * sin($obliquity)),
        )));
    }

    /**
     * Each quadrant between the angles divided into three equal parts.
     *
     * @return list<float>
     */
    private static function porphyry(float $ascendant, float $midheaven): array
    {
        $ic = self::normalize($midheaven + 180);
        $descendant = self::normalize($ascendant + 180);
        $third = fn (float $from, float $to) => self::normalize($to - $from) / 3;

        $cusps = [];
        foreach ([[$ascendant, $ic], [$ic, $descendant], [$descendant, $midheaven], [$midheaven, $ascendant]] as [$from, $to]) {
            $step = $third($from, $to);
            array_push($cusps, $from, self::normalize($from + $step), self::normalize($from + 2 * $step));
        }

        return $cusps;
    }

    private static function normalize(float $degrees): float
    {
        $degrees = fmod($degrees, 360);

        return $degrees < 0 ? $degrees + 360 : $degrees;
    }
}
