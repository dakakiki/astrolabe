<?php

namespace App\Astrology\Services;

use App\Astrology\ValueObjects\Aspect;
use App\Astrology\ValueObjects\AspectSettings;
use App\Astrology\ValueObjects\ChartResult;
use App\Enums\CelestialBody;
use App\Models\ChartCalculation;

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
        $aspects = [];

        foreach ($points as $i => $first) {
            foreach (array_slice($points, $i + 1) as $second) {
                // The angles always stand in the same relation to each other.
                if ($first['angle'] && $second['angle']) {
                    continue;
                }

                if ($aspect = $this->closest($first, $second, $settings)) {
                    $aspects[] = $aspect;
                }
            }
        }

        return $aspects;
    }

    /**
     * Transits to a natal chart (Phase 7a): every transiting point against
     * every natal one, the transit first. The natal chart stands still, so a
     * transit applies or separates by the transiting point's motion alone.
     *
     * @param  list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>  $transits
     * @param  list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>  $natal
     * @return list<Aspect>
     */
    public function across(array $transits, array $natal, AspectSettings $settings): array
    {
        $aspects = [];

        foreach ($transits as $transit) {
            foreach ($natal as $point) {
                if ($aspect = $this->closest($transit, ['speed' => 0.0] + $point, $settings)) {
                    $aspects[] = $aspect;
                }
            }
        }

        return $aspects;
    }

    /**
     * Synastry (Phase 7e): every point of one chart against every point of
     * another, the first chart's point first. Both charts stand still, so an
     * aspect neither applies nor separates — and two angles do make a contact
     * here, since they belong to different charts.
     *
     * @param  list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>  $first
     * @param  list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>  $second
     * @return list<Aspect>
     */
    public function betweenCharts(array $first, array $second, AspectSettings $settings): array
    {
        $aspects = [];

        foreach ($first as $one) {
            foreach ($second as $other) {
                if ($aspect = $this->closest(['speed' => null] + $one, ['speed' => null] + $other, $settings)) {
                    $aspects[] = $aspect;
                }
            }
        }

        return $aspects;
    }

    /**
     * The closest enabled aspect between two points within its orb, if any.
     *
     * @param  array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}  $first
     * @param  array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}  $second
     */
    private function closest(array $first, array $second, AspectSettings $settings): ?Aspect
    {
        $delta = self::signedDistance($first['longitude'], $second['longitude']);
        $distance = abs($delta);
        $luminary = $first['luminary'] || $second['luminary'];
        $found = null;

        foreach ($settings->enabledTypes() as $type) {
            $orb = abs($distance - $type->angle());

            if ($orb <= $settings->orbFor($type, $luminary) && ($found === null || $orb < $found[1])) {
                $found = [$type, $orb];
            }
        }

        if ($found === null) {
            return null;
        }

        [$type, $orb] = $found;

        return new Aspect(
            first: $first['key'],
            second: $second['key'],
            type: $type,
            orb: $orb,
            applying: self::applying($delta, $type->angle(), $first['speed'], $second['speed']),
        );
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

    /**
     * The same points read back from a stored chart — what transits and
     * another person's chart are measured against: the bodies without the
     * mean node, and without the Moon when the birth time is unknown, then the
     * Ascendant and Midheaven when the chart has them.
     *
     * @return list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>
     */
    public static function storedPoints(ChartCalculation $chart): array
    {
        $timeKnown = $chart->time_accuracy->hasTime();
        $points = [];

        foreach ($chart->payload['positions'] as $position) {
            $body = CelestialBody::tryFrom($position['body']);

            if ($body === null || ! $body->takesAspects() || (! $timeKnown && $body === CelestialBody::Moon)) {
                continue;
            }

            $points[] = [
                'key' => $body->value,
                'longitude' => (float) $position['longitude'],
                'speed' => (float) $position['speed'],
                'luminary' => $body->isLuminary(),
                'angle' => false,
            ];
        }

        foreach (['asc', 'mc'] as $angle) {
            if (isset($chart->payload['angles'][$angle])) {
                $points[] = ['key' => $angle, 'longitude' => (float) $chart->payload['angles'][$angle], 'speed' => null, 'luminary' => false, 'angle' => true];
            }
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
