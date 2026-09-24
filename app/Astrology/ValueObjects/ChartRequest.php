<?php

namespace App\Astrology\ValueObjects;

use App\Enums\Ayanamsa;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;

/**
 * Everything an engine needs for one calculation (docs/spec/11). Immutable, and
 * normalised so the same input always hashes the same.
 */
final readonly class ChartRequest
{
    /**
     * @param  list<CelestialBody>  $bodies
     */
    public function __construct(
        public float $julianDayUt,
        public ?float $latitude,
        public ?float $longitude,
        public ?HouseSystem $houseSystem,
        public ZodiacMode $zodiacMode,
        public ?Ayanamsa $ayanamsa,
        public array $bodies,
        public bool $includeHouses = false,
    ) {}

    /**
     * Angles and houses need a birth time (decided by the caller from
     * time_accuracy), a place and a house system.
     */
    public function wantsHouses(): bool
    {
        return $this->includeHouses
            && $this->houseSystem !== null
            && $this->latitude !== null
            && $this->longitude !== null;
    }

    /**
     * The request as plain values, rounded to what the engine can resolve:
     * 1e-8 day is under a millisecond, 1e-6 degree about 10 cm.
     *
     * @return array<string, mixed>
     */
    public function normalized(): array
    {
        return [
            'julian_day_ut' => sprintf('%.8f', $this->julianDayUt),
            'latitude' => $this->latitude === null ? null : sprintf('%.6f', $this->latitude),
            'longitude' => $this->longitude === null ? null : sprintf('%.6f', $this->longitude),
            'house_system' => $this->houseSystem?->value,
            'zodiac_mode' => $this->zodiacMode->value,
            'ayanamsa' => $this->zodiacMode === ZodiacMode::Sidereal ? $this->ayanamsa?->value : null,
            'bodies' => array_map(fn (CelestialBody $body) => $body->value, $this->bodies),
            'include_houses' => $this->includeHouses,
        ];
    }
}
