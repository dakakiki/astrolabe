<?php

namespace App\Enums;

/**
 * Aspects a chart can show (docs/spec/11). The five major aspects are on by
 * default; the minor ones wait until a workspace switches them on.
 */
enum AspectType: string
{
    case Conjunction = 'conjunction';
    case Sextile = 'sextile';
    case Square = 'square';
    case Trine = 'trine';
    case Opposition = 'opposition';
    case Quincunx = 'quincunx';
    case Semisextile = 'semisextile';
    case Semisquare = 'semisquare';
    case Sesquisquare = 'sesquisquare';

    /** The exact angle between the two points, in degrees. */
    public function angle(): float
    {
        return match ($this) {
            self::Conjunction => 0.0,
            self::Semisextile => 30.0,
            self::Semisquare => 45.0,
            self::Sextile => 60.0,
            self::Square => 90.0,
            self::Trine => 120.0,
            self::Sesquisquare => 135.0,
            self::Quincunx => 150.0,
            self::Opposition => 180.0,
        };
    }

    public function isMajor(): bool
    {
        return in_array($this, [self::Conjunction, self::Sextile, self::Square, self::Trine, self::Opposition], true);
    }

    /**
     * Default orb in degrees. Temporary values until the validation interviews
     * (docs/spec/11, open question 4); every workspace can change them.
     */
    public function defaultOrb(): float
    {
        return match ($this) {
            self::Conjunction => 8.0,
            self::Opposition => 7.0,
            self::Square, self::Trine => 6.0,
            self::Sextile => 4.0,
            self::Quincunx => 3.0,
            self::Semisextile, self::Semisquare, self::Sesquisquare => 2.0,
        };
    }

    /**
     * Default orb for a transit to the natal chart — much tighter than between
     * natal points (Phase 7a, temporary until the validation interviews; every
     * workspace can change it).
     */
    public function defaultTransitOrb(): float
    {
        return match ($this) {
            self::Conjunction, self::Opposition, self::Square, self::Trine => 2.0,
            self::Sextile => 1.5,
            self::Quincunx, self::Semisextile, self::Semisquare, self::Sesquisquare => 1.0,
        };
    }
}
