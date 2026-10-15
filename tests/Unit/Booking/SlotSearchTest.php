<?php

namespace Tests\Unit\Booking;

use App\Support\Booking\Intervals;
use App\Support\Booking\SlotSearch;
use App\Support\Booking\WeeklyHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the portal's slot search (Phase 9b) on made-up weeks: no
 * database, no clock. Times are written as UTC "H:i" on one day for reading.
 */
class SlotSearchTest extends TestCase
{
    private const DAY = '2026-11-02'; // a Monday

    private function at(string $time, string $day = self::DAY): int
    {
        return CarbonImmutable::parse("{$day} {$time}", 'UTC')->getTimestamp();
    }

    /**
     * @param  list<int>  $starts
     * @return list<string>
     */
    private function times(array $starts): array
    {
        return array_map(fn (int $start) => gmdate('H:i', $start), $starts);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return list<string>
     */
    private function find(array $overrides = []): array
    {
        $arguments = $overrides + [
            'week' => [[$this->at('09:00'), $this->at('12:00')]],
            'extra' => [],
            'timeOff' => [],
            'busy' => [],
            'duration' => 3600,
            'step' => 1800,
            'bufferBefore' => 0,
            'bufferAfter' => 0,
            'earliest' => $this->at('00:00'),
            'latest' => $this->at('23:59'),
        ];

        return $this->times(SlotSearch::find(...$arguments));
    }

    public function test_intervals_merge_subtract_and_answer_coverage(): void
    {
        $this->assertSame([[1, 5], [6, 8]], Intervals::merge([[6, 8], [1, 3], [3, 5], [4, 4]]));
        $this->assertSame([[0, 2], [4, 6], [9, 10]], Intervals::subtract([[0, 10]], [[2, 4], [6, 9]]));
        $this->assertSame([], Intervals::subtract([[2, 4]], [[0, 10]]));

        $merged = [[0, 10], [20, 30]];
        $this->assertTrue(Intervals::covers($merged, 2, 10));
        $this->assertFalse(Intervals::covers($merged, 5, 21));
        $this->assertFalse(Intervals::covers($merged, 12, 15));
        $this->assertTrue(Intervals::overlapsAny($merged, 9, 11));
        $this->assertFalse(Intervals::overlapsAny($merged, 10, 20), 'touching ends do not overlap');
        $this->assertTrue(Intervals::overlapsAny($merged, 15, 21));
    }

    public function test_slots_step_through_working_time_and_fit_the_whole_appointment(): void
    {
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00'], $this->find());
        $this->assertSame(['09:00', '10:00', '11:00'], $this->find(['step' => 3600]));
        $this->assertSame([], $this->find(['duration' => 4 * 3600]));
    }

    public function test_the_grid_starts_at_each_stretch_and_time_off_does_not_shift_it(): void
    {
        $working = [[$this->at('09:15'), $this->at('11:15')], [$this->at('14:00'), $this->at('15:00')]];
        $this->assertSame(['09:15', '09:45', '10:15', '14:00'], $this->find(['week' => $working]));

        $timeOff = [[$this->at('09:40'), $this->at('10:10')]];
        $this->assertSame(['10:30', '11:00'], $this->find(['timeOff' => $timeOff]));
    }

    public function test_extra_time_joins_the_week_and_an_appointment_may_span_both(): void
    {
        $working = [[$this->at('09:00'), $this->at('10:00')], [$this->at('10:00'), $this->at('11:00')]];
        $this->assertSame(['09:00', '09:30', '10:00'], $this->find(['week' => $working]));

        // The same with the second hour as extra time: one stretch of open time, and its own grid too.
        $this->assertSame(['09:00', '09:30', '10:00'], $this->find([
            'week' => [[$this->at('09:00'), $this->at('10:00')]],
            'extra' => [[$this->at('10:00'), $this->at('11:00')]],
        ]));
        $this->assertSame(['09:00', '09:30', '10:00', '10:10', '10:30', '10:40'], $this->find([
            'week' => [[$this->at('09:00'), $this->at('11:00')]],
            'extra' => [[$this->at('10:10'), $this->at('11:40')]],
        ]));
    }

    public function test_time_off_cuts_the_week_but_not_extra_time(): void
    {
        // docs/spec/12: free = week − time off + extra time − appointments. A holiday from
        // Monday to Friday with a free Wednesday morning offers that morning, on its own grid.
        $week = [];
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $day) {
            $week[] = [$this->at('09:00', $day), $this->at('17:00', $day)];
        }
        $holiday = [[$this->at('00:00', '2026-11-02'), $this->at('00:00', '2026-11-07')]];
        $wednesday = [[$this->at('10:00', '2026-11-04'), $this->at('12:00', '2026-11-04')]];

        $slots = SlotSearch::find(
            week: $week,
            extra: $wednesday,
            timeOff: $holiday,
            busy: [],
            duration: 3600,
            step: 1800,
            bufferBefore: 0,
            bufferAfter: 0,
            earliest: $this->at('00:00', '2026-11-02'),
            latest: $this->at('00:00', '2026-11-09'),
        );

        $this->assertSame(
            ['2026-11-04 10:00', '2026-11-04 10:30', '2026-11-04 11:00'],
            array_map(fn (int $start) => gmdate('Y-m-d H:i', $start), $slots),
        );

        // Time off inside extra time, or around part of it, still leaves the extra time open.
        $this->assertSame(['13:00', '13:30', '14:00'], $this->find([
            'extra' => [[$this->at('13:00'), $this->at('15:00')]],
            'timeOff' => [[$this->at('08:00'), $this->at('14:30')]],
        ]));

        // Appointments and their buffers still block extra time.
        $this->assertSame(['13:00'], $this->find([
            'week' => [],
            'extra' => [[$this->at('13:00'), $this->at('16:00')]],
            'busy' => [[$this->at('14:15'), $this->at('15:00')]],
            'bufferAfter' => 900,
        ]));
    }

    public function test_appointments_keep_the_buffers_free_on_both_sides(): void
    {
        $busy = [[$this->at('10:00'), $this->at('11:00')]];

        // No buffers: touching is fine.
        $this->assertSame(['09:00', '11:00', '12:00'], $this->find(['busy' => $busy, 'week' => [[$this->at('09:00'), $this->at('13:00')]], 'step' => 3600]));

        // 15 minutes after each appointment: the next one starts 11:15 at the earliest, and one
        // before must end by 09:45.
        $slots = $this->find([
            'busy' => $busy,
            'week' => [[$this->at('08:00'), $this->at('13:00')]],
            'step' => 900,
            'bufferAfter' => 900,
        ]);
        $this->assertContains('08:45', $slots);
        $this->assertNotContains('09:00', $slots);
        $this->assertNotContains('11:00', $slots);
        $this->assertContains('11:15', $slots);

        // 10 before and 15 after: 25 minutes between any two appointments.
        $slots = $this->find([
            'busy' => $busy,
            'week' => [[$this->at('08:00'), $this->at('13:00')]],
            'step' => 300,
            'bufferBefore' => 600,
            'bufferAfter' => 900,
        ]);
        $this->assertContains('08:35', $slots);
        $this->assertNotContains('08:40', $slots);
        $this->assertNotContains('11:20', $slots);
        $this->assertContains('11:25', $slots);
    }

    public function test_slots_respect_the_earliest_and_latest_start(): void
    {
        $this->assertSame(['10:00', '10:30'], $this->find(['earliest' => $this->at('09:50'), 'latest' => $this->at('10:30')]));
        // The grid stays on the stretch's own start, not on `earliest`.
        $this->assertSame(['10:00'], $this->find(['earliest' => $this->at('09:31'), 'latest' => $this->at('10:00')]));
        $this->assertSame([], $this->find(['earliest' => $this->at('12:00'), 'latest' => $this->at('11:00')]));
    }

    public function test_a_week_follows_its_zone_across_the_clock_change(): void
    {
        // Monday 09:00–17:00 in Belgrade: 07:00 UTC before the autumn change (25 Oct 2026), 08:00 after.
        $rules = [['weekday' => 1, 'start' => 9 * 60, 'end' => 17 * 60, 'timezone' => 'Europe/Belgrade']];
        $stretches = WeeklyHours::between(
            $rules,
            CarbonImmutable::parse('2026-10-19 00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-03 00:00', 'UTC'),
        );

        $this->assertSame([
            ['2026-10-19 07:00', '2026-10-19 15:00'],
            ['2026-10-26 08:00', '2026-10-26 16:00'],
            ['2026-11-02 08:00', '2026-11-02 16:00'],
        ], array_map(fn (array $stretch) => [gmdate('Y-m-d H:i', $stretch[0]), gmdate('Y-m-d H:i', $stretch[1])], $stretches));
    }

    public function test_a_stretch_on_the_night_of_the_change_and_one_ending_at_midnight(): void
    {
        // Sunday 25 Oct 2026 in Belgrade has 25 hours: 00:00–24:00 is 22:00 UTC Saturday to 23:00 UTC Sunday.
        $rules = [['weekday' => 7, 'start' => 0, 'end' => 1440, 'timezone' => 'Europe/Belgrade']];
        $stretches = WeeklyHours::between(
            $rules,
            CarbonImmutable::parse('2026-10-25 00:00', 'UTC'),
            CarbonImmutable::parse('2026-10-25 12:00', 'UTC'),
        );

        $this->assertSame([['2026-10-24 22:00', '2026-10-25 23:00']], array_map(
            fn (array $stretch) => [gmdate('Y-m-d H:i', $stretch[0]), gmdate('Y-m-d H:i', $stretch[1])],
            $stretches,
        ));

        // New York's spring change (8 Mar 2026): 01:00–04:00 local is only two hours long.
        $rules = [['weekday' => 7, 'start' => 60, 'end' => 240, 'timezone' => 'America/New_York']];
        [$stretch] = WeeklyHours::between(
            $rules,
            CarbonImmutable::parse('2026-03-08 00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-09 00:00', 'UTC'),
        );
        $this->assertSame(2 * 3600, $stretch[1] - $stretch[0]);
    }

    public function test_weekly_hours_keep_a_stretch_that_began_before_the_window(): void
    {
        $rules = [['weekday' => 1, 'start' => 9 * 60, 'end' => 17 * 60, 'timezone' => 'UTC']];
        $stretches = WeeklyHours::between(
            $rules,
            CarbonImmutable::parse(self::DAY.' 12:00', 'UTC'),
            CarbonImmutable::parse(self::DAY.' 13:00', 'UTC'),
        );

        $this->assertSame([[$this->at('09:00'), $this->at('17:00')]], $stretches);
    }
}
