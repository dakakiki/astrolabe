<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;

/**
 * An astrologer's working week as real stretches of time (Phase 9b): each rule
 * is a weekday and wall-clock minutes in its own zone, so "Monday 09:00–17:00
 * in Belgrade" is 07:00–15:00 UTC in summer and 08:00–16:00 UTC in winter.
 * Pure code, no database (WeeklyHoursTest).
 *
 * A time that does not exist on a spring-forward night moves on with the clock
 * (Carbon's rule); a time that happens twice in autumn is its first occurrence.
 */
final class WeeklyHours
{
    /**
     * Every stretch of the week that touches [from, to), in Unix seconds and
     * whole — a stretch that began before `from` keeps its own start, because
     * the slot grid is counted from it.
     *
     * @param  iterable<array{weekday: int, start: int, end: int, timezone: string}>  $rules  minutes since local midnight; end up to 1440
     * @return list<array{0: int, 1: int}>
     */
    public static function between(iterable $rules, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stretches = [];

        foreach ($rules as $rule) {
            if ($rule['end'] <= $rule['start']) {
                continue;
            }

            $zone = $rule['timezone'];
            // A day either side: a stretch late on the day before can still run into `from`.
            $day = $from->setTimezone($zone)->startOfDay()->subDay();
            $last = $to->setTimezone($zone)->startOfDay()->addDay();

            for (; $day <= $last; $day = $day->addDay()) {
                if ($day->isoWeekday() !== $rule['weekday']) {
                    continue;
                }

                $start = self::at($day, $rule['start'], $zone);
                $end = self::at($day, $rule['end'], $zone);

                if ($end > $from->getTimestamp() && $start < $to->getTimestamp() && $end > $start) {
                    $stretches[] = [$start, $end];
                }
            }
        }

        usort($stretches, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $stretches;
    }

    /** A local day plus wall-clock minutes (1440 = the next midnight), as Unix seconds. */
    private static function at(CarbonImmutable $day, int $minutes, string $zone): int
    {
        $date = $minutes >= 1440 ? $day->addDay()->format('Y-m-d') : $day->format('Y-m-d');
        $minutes %= 1440;

        return CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            sprintf('%s %02d:%02d', $date, intdiv($minutes, 60), $minutes % 60),
            $zone,
        )->getTimestamp();
    }
}
