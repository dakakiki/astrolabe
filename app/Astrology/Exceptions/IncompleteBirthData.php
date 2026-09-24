<?php

namespace App\Astrology\Exceptions;

use RuntimeException;

/**
 * A chart was asked for, but the birth details lack what it needs.
 */
class IncompleteBirthData extends RuntimeException
{
    /**
     * @param  list<string>  $missing  e.g. ["birth_time", "timezone"]
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Birth details are incomplete: '.implode(', ', $missing));
    }
}
