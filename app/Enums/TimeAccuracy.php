<?php

namespace App\Enums;

/**
 * How far the birth time can be trusted. Decides what a chart may show
 * (docs/spec/02, "time_accuracy određuje šta se prikazuje").
 */
enum TimeAccuracy: string
{
    /** Full set: planets, angles, houses, aspects. */
    case Exact = 'exact';

    /** Full set, marked as rectified. */
    case Rectified = 'rectified';

    /** Full set, with a warning that angles and houses are unreliable. */
    case Approximate = 'approximate';

    /** Planets only, for 12:00 UT; no angles or houses; the Moon as a range. */
    case Unknown = 'unknown';

    public function hasTime(): bool
    {
        return $this !== self::Unknown;
    }
}
