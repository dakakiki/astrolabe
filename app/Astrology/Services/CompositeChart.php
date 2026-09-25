<?php

namespace App\Astrology\Services;

use App\Astrology\ValueObjects\Houses;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Enums\TimeAccuracy;
use App\Models\ChartCalculation;
use LogicException;

/**
 * The composite chart of two people (docs/spec/11, Phase 7e): every point
 * halfway between the same point in both natal charts, on the shorter arc.
 * Pure calculation on two stored charts — no engine run, nothing stored.
 *
 * Houses are the midpoints of the matching cusps, each measured from its own
 * chart's first cusp so the twelve stay in order. Whole Sign counts signs from
 * the composite Ascendant instead: halfway between two sign boundaries is no
 * boundary. When the two charts were drawn in different systems — one fell
 * back to Porphyry inside a polar circle — the composite is Porphyry from its
 * own angles. The Midheaven is taken on the side that keeps it above the
 * composite horizon.
 *
 * Without a birth time a chart has no angles, so neither has the composite,
 * and the Moon is a span: its middle is halfway between the two Moons, and it
 * is half as wide as the two spans together.
 */
final class CompositeChart
{
    /** How the houses were found, for the note beside them. */
    public const MIDPOINT_CUSPS = 'midpoint_cusps';

    public const WHOLE_SIGNS = 'whole_signs';

    public const PORPHYRY_FROM_ANGLES = 'porphyry_from_angles';

    /**
     * @return array{
     *     time_accuracy: string,
     *     positions: list<array{body: string, longitude: float, speed: null, retrograde: false, house: int|null}>,
     *     moon_range: array{from: float, to: float}|null,
     *     houses: array{system: string, requested_system: string, cusps: list<float>, method: string}|null,
     *     angles: array{asc: float, mc: float, dsc: float, ic: float}|null,
     * }
     */
    public static function of(ChartCalculation $first, ChartCalculation $second): array
    {
        $one = $first->payload;
        $two = $second->payload;
        $angles = self::angles($one['angles'] ?? null, $two['angles'] ?? null);
        $houses = $angles ? self::houses($one['houses'], $two['houses'], $angles) : null;
        $moonRange = self::moonRange($one, $two);

        $others = [];
        foreach ($two['positions'] as $position) {
            $others[$position['body']] = (float) $position['longitude'];
        }

        $positions = [];
        foreach ($one['positions'] as $position) {
            if (! isset($others[$position['body']])) {
                continue;
            }

            $longitude = $position['body'] === CelestialBody::Moon->value
                ? self::midpoint(self::moonSpan($one)[0], self::moonSpan($two)[0])
                : self::midpoint((float) $position['longitude'], $others[$position['body']]);

            $positions[] = [
                'body' => $position['body'],
                'longitude' => round($longitude, 7),
                // A midpoint has no motion of its own.
                'speed' => null,
                'retrograde' => false,
                'house' => $houses ? Houses::numberFor($houses['cusps'], $longitude) : null,
            ];
        }

        return [
            'time_accuracy' => self::accuracy($first->time_accuracy, $second->time_accuracy)->value,
            'positions' => $positions,
            'moon_range' => $moonRange,
            'houses' => $houses,
            'angles' => $angles,
        ];
    }

    /**
     * The composite's points that take aspects, as AspectCalculator reads them:
     * the bodies without the mean node — and without the Moon when it is a
     * span — then the Ascendant and Midheaven. Nothing moves.
     *
     * @param  array{positions: list<array{body: string, longitude: float}>, moon_range: array<string, float>|null, angles: array<string, float>|null}  $composite
     * @return list<array{key: string, longitude: float, speed: null, luminary: bool, angle: bool}>
     */
    public static function points(array $composite): array
    {
        $points = [];

        foreach ($composite['positions'] as $position) {
            $body = CelestialBody::from($position['body']);

            if (! $body->takesAspects() || ($body === CelestialBody::Moon && $composite['moon_range'] !== null)) {
                continue;
            }

            $points[] = ['key' => $body->value, 'longitude' => $position['longitude'], 'speed' => null, 'luminary' => $body->isLuminary(), 'angle' => false];
        }

        foreach (['asc', 'mc'] as $angle) {
            if (isset($composite['angles'][$angle])) {
                $points[] = ['key' => $angle, 'longitude' => $composite['angles'][$angle], 'speed' => null, 'luminary' => false, 'angle' => true];
            }
        }

        return $points;
    }

    /**
     * Halfway between two longitudes on the shorter arc. Exactly opposite
     * points have two midpoints; the one 90° after the first is taken.
     */
    public static function midpoint(float $first, float $second): float
    {
        return self::normalize($first + self::wrap($second - $first) / 2);
    }

    /**
     * @param  array<string, float>|null  $one
     * @param  array<string, float>|null  $two
     * @return array{asc: float, mc: float, dsc: float, ic: float}|null
     */
    private static function angles(?array $one, ?array $two): ?array
    {
        if (! isset($one['asc'], $one['mc'], $two['asc'], $two['mc'])) {
            return null;
        }

        $ascendant = self::midpoint($one['asc'], $two['asc']);
        // Halfway between how far each Midheaven lies from its own Ascendant, so it
        // never lands below the composite horizon when the two MCs are far apart.
        $midheaven = self::normalize($ascendant + (self::normalize($one['mc'] - $one['asc']) + self::normalize($two['mc'] - $two['asc'])) / 2);

        return [
            'asc' => round($ascendant, 7),
            'mc' => round($midheaven, 7),
            'dsc' => round(self::normalize($ascendant + 180), 7),
            'ic' => round(self::normalize($midheaven + 180), 7),
        ];
    }

    /**
     * @param  array{system: string, requested_system: string, cusps: list<float>}  $one
     * @param  array{system: string, requested_system: string, cusps: list<float>}  $two
     * @param  array{asc: float, mc: float}  $angles
     * @return array{system: string, requested_system: string, cusps: list<float>, method: string}
     */
    private static function houses(array $one, array $two, array $angles): array
    {
        $requested = $one['requested_system'];

        if ($one['system'] !== $two['system']) {
            $system = HouseSystem::Porphyry;
            $method = self::PORPHYRY_FROM_ANGLES;
            $cusps = Houses::porphyry($angles['asc'], $angles['mc']);
        } elseif ($one['system'] === HouseSystem::WholeSign->value) {
            $system = HouseSystem::WholeSign;
            $method = self::WHOLE_SIGNS;
            $first = floor($angles['asc'] / 30) * 30;
            $cusps = array_map(fn (int $house) => self::normalize($first + 30 * $house), range(0, 11));
        } else {
            $system = HouseSystem::from($one['system']);
            $method = self::MIDPOINT_CUSPS;
            $first = self::midpoint($one['cusps'][0], $two['cusps'][0]);
            $cusps = array_map(
                fn (int $house) => self::normalize($first + (
                    self::normalize($one['cusps'][$house] - $one['cusps'][0]) + self::normalize($two['cusps'][$house] - $two['cusps'][0])
                ) / 2),
                range(0, 11),
            );
        }

        return [
            'system' => $system->value,
            'requested_system' => $requested,
            'cusps' => array_map(fn (float $cusp) => round($cusp, 7), $cusps),
            'method' => $method,
        ];
    }

    /**
     * The composite Moon's span when either birth time is unknown.
     *
     * @param  array<string, mixed>  $one
     * @param  array<string, mixed>  $two
     * @return array{from: float, to: float}|null
     */
    private static function moonRange(array $one, array $two): ?array
    {
        if (! isset($one['moon_range']) && ! isset($two['moon_range'])) {
            return null;
        }

        [$middleOne, $spanOne] = self::moonSpan($one);
        [$middleTwo, $spanTwo] = self::moonSpan($two);
        $middle = self::midpoint($middleOne, $middleTwo);
        $half = ($spanOne + $spanTwo) / 4;

        return [
            'from' => round(self::normalize($middle - $half), 7),
            'to' => round(self::normalize($middle + $half), 7),
        ];
    }

    /**
     * Where a chart's Moon is — the middle of its span without a birth time —
     * and how wide that span is (0 with a time).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: float, 1: float}
     */
    private static function moonSpan(array $payload): array
    {
        if (isset($payload['moon_range'])) {
            $span = self::normalize($payload['moon_range']['to'] - $payload['moon_range']['from']);

            return [self::normalize($payload['moon_range']['from'] + $span / 2), $span];
        }

        foreach ($payload['positions'] as $position) {
            if ($position['body'] === CelestialBody::Moon->value) {
                return [(float) $position['longitude'], 0.0];
            }
        }

        throw new LogicException('A chart without the Moon.');
    }

    /** The less certain of the two birth times decides how far the composite can be read. */
    private static function accuracy(TimeAccuracy $first, TimeAccuracy $second): TimeAccuracy
    {
        foreach ([TimeAccuracy::Unknown, TimeAccuracy::Approximate] as $weaker) {
            if ($first === $weaker || $second === $weaker) {
                return $weaker;
            }
        }

        return TimeAccuracy::Exact;
    }

    /** Into [0°, 360°). */
    private static function normalize(float $degrees): float
    {
        $degrees = fmod($degrees, 360);

        return $degrees < 0 ? $degrees + 360 : $degrees;
    }

    /** Into (-180°, 180°]. */
    private static function wrap(float $degrees): float
    {
        $degrees = fmod($degrees, 360);

        if ($degrees > 180) {
            return $degrees - 360;
        }

        return $degrees <= -180 ? $degrees + 360 : $degrees;
    }
}
