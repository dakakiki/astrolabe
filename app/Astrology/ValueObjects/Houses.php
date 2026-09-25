<?php

namespace App\Astrology\ValueObjects;

use App\Enums\HouseSystem;
use InvalidArgumentException;

/**
 * The angles and the twelve house cusps of a chart, as ecliptic longitudes in
 * the chart's zodiac. `system` is what was calculated; it differs from
 * `requestedSystem` when a system cannot be drawn at the birth latitude and
 * the engine fell back to another one (docs/spec/11).
 */
final readonly class Houses
{
    /**
     * @param  list<float>  $cusps  cusp 1 first
     */
    public function __construct(
        public HouseSystem $system,
        public HouseSystem $requestedSystem,
        public array $cusps,
        public float $ascendant,
        public float $midheaven,
        public float $armc,
        public float $vertex,
    ) {
        if (count($cusps) !== 12) {
            throw new InvalidArgumentException('A chart has twelve house cusps, got '.count($cusps).'.');
        }
    }

    public function fellBack(): bool
    {
        return $this->system !== $this->requestedSystem;
    }

    /**
     * The house (1–12) a longitude falls in: from its cusp up to, not
     * including, the next one, across 0° Aries where a house spans it.
     */
    public function houseOf(float $longitude): int
    {
        return self::numberFor($this->cusps, $longitude);
    }

    /**
     * The same for cusps read back from a stored chart, e.g. where a transiting
     * planet stands in the natal houses.
     *
     * @param  list<float>  $cusps  twelve longitudes, house 1 first
     */
    public static function numberFor(array $cusps, float $longitude): int
    {
        $longitude = self::normalize($longitude);

        foreach ($cusps as $index => $cusp) {
            $next = $cusps[($index + 1) % 12];
            $span = self::normalize($next - $cusp);

            if (self::normalize($longitude - $cusp) < $span) {
                return $index + 1;
            }
        }

        // Only reachable with degenerate cusps (all equal); the first house holds everything.
        return 1;
    }

    /**
     * Porphyry cusps, which need nothing but the angles: each quadrant
     * between them divided into three equal parts.
     *
     * @return list<float> twelve longitudes, house 1 first
     */
    public static function porphyry(float $ascendant, float $midheaven): array
    {
        $ic = self::normalize($midheaven + 180);
        $descendant = self::normalize($ascendant + 180);
        $cusps = [];

        foreach ([[$ascendant, $ic], [$ic, $descendant], [$descendant, $midheaven], [$midheaven, $ascendant]] as [$from, $to]) {
            $step = self::normalize($to - $from) / 3;
            array_push($cusps, $from, self::normalize($from + $step), self::normalize($from + 2 * $step));
        }

        return $cusps;
    }

    /**
     * @return array{houses: array{system: string, requested_system: string, cusps: list<float>}, angles: array<string, float>}
     */
    public function toArray(): array
    {
        return [
            'houses' => [
                'system' => $this->system->value,
                'requested_system' => $this->requestedSystem->value,
                'cusps' => array_map(fn (float $cusp) => round($cusp, 7), $this->cusps),
            ],
            'angles' => [
                'asc' => round($this->ascendant, 7),
                'mc' => round($this->midheaven, 7),
                'dsc' => round(self::normalize($this->ascendant + 180), 7),
                'ic' => round(self::normalize($this->midheaven + 180), 7),
                'vertex' => round($this->vertex, 7),
                'armc' => round($this->armc, 7),
            ],
        ];
    }

    /** Into [0°, 360°). Adds 360° only when needed: 246.9 + 360 − 360 is not 246.9 in floating point. */
    private static function normalize(float $degrees): float
    {
        $degrees = fmod($degrees, 360);

        return $degrees < 0 ? $degrees + 360 : $degrees;
    }
}
