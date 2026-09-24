<?php

namespace App\Astrology\Contracts;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;

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

    public function name(): string;

    public function version(): string;

    /**
     * Engine, version and ephemeris data in one string. Part of a chart's cache
     * key, so a new engine or new ephemeris files lead to a new calculation.
     */
    public function fingerprint(): string;
}
