<?php

namespace App\Astrology\ValueObjects;

use App\Enums\CelestialBody;

/**
 * A body's ecliptic longitude (0–360°, in the requested zodiac) and its daily
 * motion. A negative speed means the body is retrograde.
 */
final readonly class PlanetPosition
{
    public function __construct(
        public CelestialBody $body,
        public float $longitude,
        public float $speed,
    ) {}

    public function isRetrograde(): bool
    {
        return $this->speed < 0;
    }

    /**
     * @return array{body: string, longitude: float, speed: float, retrograde: bool}
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body->value,
            'longitude' => round($this->longitude, 7),
            'speed' => round($this->speed, 7),
            'retrograde' => $this->isRetrograde(),
        ];
    }
}
