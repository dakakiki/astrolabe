<?php

namespace App\Support\Booking;

/**
 * Stretches of time as half-open pairs of Unix seconds, [start, end): touching
 * stretches (10–11 and 11–12) do not overlap. Pure helpers for the slot search.
 */
final class Intervals
{
    /**
     * Sorted, with overlapping and touching stretches joined.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    public static function merge(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, fn (array $interval) => $interval[1] > $interval[0]));
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * What is left of `$from` once `$remove` is taken out. Both are merged first.
     *
     * @param  list<array{0: int, 1: int}>  $from
     * @param  list<array{0: int, 1: int}>  $remove
     * @return list<array{0: int, 1: int}>
     */
    public static function subtract(array $from, array $remove): array
    {
        $remove = self::merge($remove);
        $left = [];

        foreach (self::merge($from) as [$start, $end]) {
            $cursor = $start;

            foreach ($remove as [$cutStart, $cutEnd]) {
                if ($cutEnd <= $cursor) {
                    continue;
                }

                if ($cutStart >= $end) {
                    break;
                }

                if ($cutStart > $cursor) {
                    $left[] = [$cursor, $cutStart];
                }

                $cursor = max($cursor, $cutEnd);

                if ($cursor >= $end) {
                    break;
                }
            }

            if ($cursor < $end) {
                $left[] = [$cursor, $end];
            }
        }

        return $left;
    }

    /**
     * Whether [start, end) lies wholly inside one of the merged, sorted stretches.
     *
     * @param  list<array{0: int, 1: int}>  $merged
     */
    public static function covers(array $merged, int $start, int $end): bool
    {
        $index = self::lastStartingAtOrBefore($merged, $start);

        return $index !== null && $merged[$index][1] >= $end;
    }

    /**
     * Whether [start, end) overlaps any of the merged, sorted stretches.
     *
     * @param  list<array{0: int, 1: int}>  $merged
     */
    public static function overlapsAny(array $merged, int $start, int $end): bool
    {
        $index = self::lastStartingAtOrBefore($merged, $start);

        // The stretch that began before `start` may still be running…
        if ($index !== null && $merged[$index][1] > $start) {
            return true;
        }

        // …or the next one may begin before `end`.
        $next = $index === null ? 0 : $index + 1;

        return isset($merged[$next]) && $merged[$next][0] < $end;
    }

    /**
     * Binary search: the last stretch whose start is at or before the moment.
     *
     * @param  list<array{0: int, 1: int}>  $merged
     */
    private static function lastStartingAtOrBefore(array $merged, int $moment): ?int
    {
        [$low, $high, $found] = [0, count($merged) - 1, null];

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($merged[$middle][0] <= $moment) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }
}
