<?php

namespace App\Astrology\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class JulianDay
{
    /** Julian Day of the Unix epoch, 1970-01-01 00:00 UTC. */
    private const UNIX_EPOCH = 2440587.5;

    /**
     * The Julian Day (UT) of a moment. The engine is given UT; UTC differs from
     * UT1 by under 0.9 s, which moves even the Moon by less than half an arc-second.
     */
    public static function fromMoment(DateTimeInterface $moment): float
    {
        $seconds = $moment->getTimestamp() + ((int) $moment->format('u')) / 1_000_000;

        return self::UNIX_EPOCH + $seconds / 86400;
    }

    /** The moment (UTC, to the second) of a Julian Day (UT). */
    public static function toMoment(float $julianDay): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC((int) round(($julianDay - self::UNIX_EPOCH) * 86400));
    }
}
