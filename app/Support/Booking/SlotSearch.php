<?php

namespace App\Support\Booking;

/**
 * Which start times the portal may offer (docs/spec/12, "Pravila"). Pure code on
 * Unix seconds, no database and no clock (SlotSearchTest):
 *
 * - candidates step through each stretch of working time from its own start
 *   (a rule's start, or extra time's start), so time off in the middle of a day
 *   does not shift the grid;
 * - the whole appointment must lie inside open time: the week minus time off,
 *   plus extra time ("radno vreme − odsustva + dodatna dostupnost") — so extra
 *   time inside time off, a free Wednesday morning in a week's holiday, is offered;
 * - every existing appointment keeps the buffers clear around it: a new one
 *   needs `before + after` free between them on either side (wrapping up after
 *   the first plus getting ready for the second); touching is fine when both are 0;
 * - it starts between `earliest` and `latest`, inclusive.
 */
final class SlotSearch
{
    /**
     * @param  list<array{0: int, 1: int}>  $week  the week's stretches, any order
     * @param  list<array{0: int, 1: int}>  $extra  extra time, any order; time off does not cut it
     * @param  list<array{0: int, 1: int}>  $timeOff
     * @param  list<array{0: int, 1: int}>  $busy  appointments that hold their time
     * @return list<int> start times, ascending
     */
    public static function find(
        array $week,
        array $extra,
        array $timeOff,
        array $busy,
        int $duration,
        int $step,
        int $bufferBefore,
        int $bufferAfter,
        int $earliest,
        int $latest,
    ): array {
        if ($duration <= 0 || $step <= 0 || $latest < $earliest) {
            return [];
        }

        $open = Intervals::merge([...Intervals::subtract($week, $timeOff), ...$extra]);
        $gap = max(0, $bufferBefore) + max(0, $bufferAfter);
        $blocked = Intervals::merge(array_map(fn (array $interval) => [$interval[0] - $gap, $interval[1] + $gap], $busy));

        $starts = [];

        foreach ([...$week, ...$extra] as [$from, $to]) {
            // The first grid point at or after `earliest`, counted from this stretch's own start.
            $first = $from >= $earliest ? $from : $from + (int) ceil(($earliest - $from) / $step) * $step;

            for ($start = $first; $start < $to && $start <= $latest; $start += $step) {
                $end = $start + $duration;

                if (Intervals::covers($open, $start, $end) && ! Intervals::overlapsAny($blocked, $start, $end)) {
                    $starts[$start] = true;
                }
            }
        }

        $starts = array_keys($starts);
        sort($starts);

        return $starts;
    }
}
