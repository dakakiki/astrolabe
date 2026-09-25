<?php

namespace App\Astrology\Contracts;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Astrology\ValueObjects\PlanetPosition;

/**
 * The only way into an ephemeris (docs/spec/03, docs/spec/11). No controller,
 * model or component calls an ephemeris library directly.
 */
interface EphemerisEngine
{
    /**
     * @throws EphemerisException when the engine is missing, misconfigured or fails
     */
    public function calculate(ChartRequest $request): ChartResult;

    /**
     * Positions only (no houses) at `$steps` moments `$stepDays` apart, the
     * first at the request's moment — in one go, since starting the engine
     * costs far more than the calculation (transits, Phase 7a).
     *
     * @return list<list<PlanetPosition>> one list per moment, bodies in the request's order
     *
     * @throws EphemerisException when the engine is missing, misconfigured or fails
     */
    public function series(ChartRequest $request, int $steps, float $stepDays = 1.0): array;

    public function name(): string;

    public function version(): string;

    /**
     * Engine, version and ephemeris data in one string. Part of a chart's cache
     * key, so a new engine or new ephemeris files lead to a new calculation.
     */
    public function fingerprint(): string;
}
