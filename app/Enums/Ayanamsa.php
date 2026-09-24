<?php

namespace App\Enums;

/**
 * Ayanamsas offered for the sidereal zodiac, with their Swiss Ephemeris
 * SE_SIDM_* constants.
 */
enum Ayanamsa: string
{
    case Lahiri = 'lahiri';
    case Raman = 'raman';
    case Krishnamurti = 'krishnamurti';
    case FaganBradley = 'fagan_bradley';
    case Yukteshwar = 'yukteshwar';
    case TrueCitra = 'true_citra';

    public function swissEphemerisMode(): int
    {
        return match ($this) {
            self::FaganBradley => 0,
            self::Lahiri => 1,
            self::Raman => 3,
            self::Krishnamurti => 5,
            self::Yukteshwar => 7,
            self::TrueCitra => 27,
        };
    }
}
