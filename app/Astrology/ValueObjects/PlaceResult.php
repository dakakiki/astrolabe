<?php

namespace App\Astrology\ValueObjects;

use App\Enums\GeocodeSource;

/**
 * A resolved place: everything needed to freeze a birth location — name,
 * country, coordinates and the IANA time zone.
 */
final readonly class PlaceResult
{
    public function __construct(
        public string $id,
        public string $name,
        public string $label,
        public ?string $countryCode,
        public float $latitude,
        public float $longitude,
        public ?string $timezone,
        public int $population,
        public GeocodeSource $source,
    ) {}
}
