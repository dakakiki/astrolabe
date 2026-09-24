<?php

namespace App\Enums;

/**
 * House systems offered as chart defaults. Each maps to the one-letter code
 * the Swiss Ephemeris expects, so the engine adapter never guesses.
 */
enum HouseSystem: string
{
    case Placidus = 'placidus';
    case Koch = 'koch';
    case Porphyry = 'porphyry';
    case Regiomontanus = 'regiomontanus';
    case Campanus = 'campanus';
    case Equal = 'equal';
    case WholeSign = 'whole_sign';
    case Alcabitius = 'alcabitius';
    case Topocentric = 'topocentric';
    case Morinus = 'morinus';

    public function swissEphemerisCode(): string
    {
        return match ($this) {
            self::Placidus => 'P',
            self::Koch => 'K',
            self::Porphyry => 'O',
            self::Regiomontanus => 'R',
            self::Campanus => 'C',
            self::Equal => 'E',
            self::WholeSign => 'W',
            self::Alcabitius => 'B',
            self::Topocentric => 'T',
            self::Morinus => 'M',
        };
    }
}
