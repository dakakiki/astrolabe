<?php

namespace App\Enums;

enum ZodiacMode: string
{
    case Tropical = 'tropical';

    /** Requires an ayanamsa. */
    case Sidereal = 'sidereal';
}
