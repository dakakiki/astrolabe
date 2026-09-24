<?php

namespace App\Astrology\ValueObjects;

use App\Enums\CelestialBody;

/**
 * An engine's answer: positions plus what produced them, so a stored chart can
 * always say which engine and ephemeris files it came from (docs/spec/02).
 */
final readonly class ChartResult
{
    /**
     * @param  list<PlanetPosition>  $positions
     */
    public function __construct(
        public array $positions,
        public string $engineName,
        public string $engineVersion,
        public ?string $ephemerisVersion,
    ) {}

    public function position(CelestialBody $body): ?PlanetPosition
    {
        foreach ($this->positions as $position) {
            if ($position->body === $body) {
                return $position;
            }
        }

        return null;
    }
}
