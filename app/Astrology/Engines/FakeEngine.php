<?php

namespace App\Astrology\Engines;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\CelestialBody;
use App\Enums\ZodiacMode;

/**
 * A stand-in for tests and CI (docs/spec/11): deterministic, needs no binary,
 * data files or licence. Positions follow each body's mean motion from J2000 —
 * plausible, never correct. Tests can pin exact positions with fix().
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
        'true_node' => [125.045, -0.052954],
        'mean_node' => [125.045, -0.052954],
    ];

    private const J2000 = 2451545.0;

    /** A fixed ayanamsa, so sidereal charts differ from tropical ones in tests. */
    public const AYANAMSA = 24.0;

    /** @var array<string, array{0: float, 1: float}> body => [longitude, speed] */
    private array $fixed = [];

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
     */
    public function fix(CelestialBody $body, float $longitude, float $speed): static
    {
        $this->fixed[$body->value] = [$longitude, $speed];

        return $this;
    }

    public function calculate(ChartRequest $request): ChartResult
    {
        $this->calls++;
        $days = $request->julianDayUt - self::J2000;
        $shift = $request->zodiacMode === ZodiacMode::Sidereal ? self::AYANAMSA : 0.0;

        $positions = array_map(function (CelestialBody $body) use ($days, $shift) {
            [$longitude, $speed] = $this->fixed[$body->value] ?? [
                self::MEAN_MOTION[$body->value][0] + self::MEAN_MOTION[$body->value][1] * $days,
                self::MEAN_MOTION[$body->value][1],
            ];

            return new PlanetPosition($body, fmod(fmod($longitude - $shift, 360) + 360, 360), $speed);
        }, $request->bodies);

        return new ChartResult($positions, $this->name(), $this->version(), null);
    }
}
