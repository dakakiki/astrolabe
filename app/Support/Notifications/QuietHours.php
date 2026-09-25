<?php

namespace App\Support\Notifications;

use Carbon\CarbonImmutable;

/**
 * A daily stretch without email, on the recipient's own clock. "22:00"–"08:00"
 * runs overnight; the start belongs to it, the end does not.
 */
final readonly class QuietHours
{
    public function __construct(
        public string $start,
        public string $end,
    ) {}

    /** Whether a wall-clock time ("07:30") falls inside. */
    public function covers(string $time): bool
    {
        $minute = self::minutes($time);
        [$start, $end] = [self::minutes($this->start), self::minutes($this->end)];

        return match (true) {
            $start === $end => false,
            $start < $end => $minute >= $start && $minute < $end,
            default => $minute >= $start || $minute < $end,
        };
    }

    /**
     * When to send something meant for `$moment` without breaking the quiet:
     * the moment itself when it is outside; otherwise when the quiet ends — or,
     * if that is not before `$deadline`, the minute before it began.
     */
    public function shift(CarbonImmutable $moment, string $zone, ?CarbonImmutable $deadline = null): CarbonImmutable
    {
        $local = $moment->setTimezone($zone);

        if (! $this->covers($local->format('H:i'))) {
            return $moment;
        }

        [$began, $ends] = $this->around($local);

        return $deadline === null || $ends < $deadline ? $ends->utc() : $began->subMinute()->utc();
    }

    /**
     * The quiet stretch a local moment inside it belongs to: when it began and when it ends.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function around(CarbonImmutable $local): array
    {
        $overnight = self::minutes($this->start) > self::minutes($this->end);
        $day = $local->startOfDay();

        // After midnight in an overnight stretch, it began the evening before.
        $startDay = $overnight && self::minutes($local->format('H:i')) < self::minutes($this->end) ? $day->subDay() : $day;
        $endDay = $overnight ? $startDay->addDay() : $startDay;

        return [self::at($startDay, $this->start), self::at($endDay, $this->end)];
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $day->setTime($hours, $minutes);
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
