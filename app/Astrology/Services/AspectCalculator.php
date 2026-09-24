<?php

namespace App\Astrology\Services;

use App\Astrology\ValueObjects\Aspect;
use App\Astrology\ValueObjects\AspectSettings;
use App\Astrology\ValueObjects\ChartResult;
use App\Enums\CelestialBody;

/**
 * Aspects between the points of a chart, on the server (docs/spec/11).
 *
 * Each pair gets at most one aspect — the closest enabled one within its orb.
 * The orb widens by the workspace's luminary bonus when the Sun or the Moon
 * takes part. An aspect is applying when the distance to exactness shrinks
 * with the points' current daily motion, separating when it grows.
 */
class AspectCalculator
{
    /**
     * @param  list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>  $points  in display order
     * @return list<Aspect> in the order of the points
     */
    public function between(array $points, AspectSettings $settings): array
    {
        $types = $settings->enabledTypes();
        $aspects = [];

        foreach ($points as $i => $first) {
            foreach (array_slice($points, $i + 1) as $second) {
                // The angles always stand in the same relation to each other.
                if ($first['angle'] && $second['angle']) {
                    continue;
                }

                $delta = self::signedDistance($first['longitude'], $second['longitude']);
                $distance = abs($delta);
                $luminary = $first['luminary'] || $second['luminary'];
                $found = null;

                foreach ($types as $type) {
                    $orb = abs($distance - $type->angle());

                    if ($orb <= $settings->orbFor($type, $luminary) && ($found === null || $orb < $found[1])) {
                        $found = [$type, $orb];
                    }
                }

                if ($found === null) {
                    continue;
                }

                [$type, $orb] = $found;

                $aspects[] = new Aspect(
                    first: $first['key'],
                    second: $second['key'],
                    type: $type,
                    orb: $orb,
                    applying: self::applying($delta, $type->angle(), $first['speed'], $second['speed']),
                );
            }
        }

        return $aspects;
    }

    /**
     * The points of a natal chart that take aspects: the bodies without the
     * mean node, then the Ascendant and the Midheaven when there are houses.
     * Without a birth time the Moon is left out: over the birth day it moves
     * about 13°, far more than any orb (docs/spec/11).
     *
     * @return list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>
     */
    public static function natalPoints(ChartResult $result, bool $timeKnown): array
    {
        $points = [];

        foreach ($result->positions as $position) {
            if (! $position->body->takesAspects() || (! $timeKnown && $position->body === CelestialBody::Moon)) {
                continue;
            }

            $points[] = [
                'key' => $position->body->value,
                'longitude' => $position->longitude,
                'speed' => $position->speed,
                'luminary' => $position->body->isLuminary(),
                'angle' => false,
            ];
        }

        if ($result->houses !== null) {
            // A natal angle has no motion of its own worth reading, so no
            // applying or separating for aspects to it.
            $points[] = ['key' => 'asc', 'longitude' => $result->houses->ascendant, 'speed' => null, 'luminary' => false, 'angle' => true];
            $points[] = ['key' => 'mc', 'longitude' => $result->houses->midheaven, 'speed' => null, 'luminary' => false, 'angle' => true];
        }

        return $points;
    }

    /** From the second point to the first, in (-180°, 180°]. */
    private static function signedDistance(float $first, float $second): float
    {
        $delta = fmod($first - $second, 360);

        if ($delta > 180) {
            $delta -= 360;
        } elseif ($delta <= -180) {
            $delta += 360;
        }

        return $delta;
    }

    private static function applying(float $delta, float $exact, ?float $firstSpeed, ?float $secondSpeed): ?bool
    {
        if ($firstSpeed === null || $secondSpeed === null) {
            return null;
        }

        // How fast the distance between the two points changes, per day.
        $closing = ($firstSpeed - $secondSpeed) * ($delta <=> 0);

        return (abs($delta) - $exact) * $closing < 0;
    }
}
