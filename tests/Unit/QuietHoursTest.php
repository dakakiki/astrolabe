<?php

namespace Tests\Unit;

use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\QuietHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Quiet hours on the recipient's own clock (Settings → Notifications).
 */
class QuietHoursTest extends TestCase
{
    public function test_an_overnight_stretch_covers_the_evening_and_the_early_morning(): void
    {
        $quiet = new QuietHours('22:00', '08:00');

        $this->assertTrue($quiet->covers('22:00'));
        $this->assertTrue($quiet->covers('23:59'));
        $this->assertTrue($quiet->covers('00:00'));
        $this->assertTrue($quiet->covers('07:59'));
        $this->assertFalse($quiet->covers('08:00'));
        $this->assertFalse($quiet->covers('21:59'));
        $this->assertFalse($quiet->covers('12:00'));
    }

    public function test_a_daytime_stretch_and_an_empty_one(): void
    {
        $quiet = new QuietHours('12:00', '14:00');

        $this->assertTrue($quiet->covers('12:00'));
        $this->assertTrue($quiet->covers('13:30'));
        $this->assertFalse($quiet->covers('14:00'));
        $this->assertFalse($quiet->covers('23:00'));

        $this->assertFalse((new QuietHours('08:00', '08:00'))->covers('08:00'));
    }

    public function test_a_moment_outside_stays_where_it_is(): void
    {
        $moment = CarbonImmutable::parse('2026-10-04 12:00', 'UTC');

        $this->assertTrue((new QuietHours('22:00', '08:00'))->shift($moment, 'Europe/Belgrade')->equalTo($moment));
    }

    public function test_a_moment_inside_waits_until_the_quiet_ends(): void
    {
        $quiet = new QuietHours('22:00', '08:00');

        // 23:30 in Belgrade (CEST): sent at 08:00 the next morning.
        $late = $quiet->shift(CarbonImmutable::parse('2026-10-04 21:30', 'UTC'), 'Europe/Belgrade');
        $this->assertSame('2026-10-05T06:00:00Z', $late->toIso8601ZuluString());

        // 06:30 in Belgrade: sent at 08:00 the same morning.
        $early = $quiet->shift(CarbonImmutable::parse('2026-10-05 04:30', 'UTC'), 'Europe/Belgrade');
        $this->assertSame('2026-10-05T06:00:00Z', $early->toIso8601ZuluString());
    }

    public function test_when_the_quiet_ends_too_late_it_goes_just_before_the_quiet_began(): void
    {
        $quiet = new QuietHours('22:00', '08:00');

        // Due 06:30 for something at 07:30: 08:00 is too late, so 21:59 the evening before.
        $moment = CarbonImmutable::parse('2026-10-05 04:30', 'UTC');
        $deadline = CarbonImmutable::parse('2026-10-05 05:30', 'UTC');

        $this->assertSame(
            '2026-10-04T19:59:00Z',
            $quiet->shift($moment, 'Europe/Belgrade', $deadline)->toIso8601ZuluString(),
        );
    }

    public function test_the_end_of_the_quiet_follows_the_clock_change(): void
    {
        $quiet = new QuietHours('22:00', '08:00');

        // Belgrade leaves summer time on 25 Oct 2026 at 03:00: 08:00 is then 07:00 UTC.
        $shifted = $quiet->shift(CarbonImmutable::parse('2026-10-25 01:00', 'UTC'), 'Europe/Belgrade');

        $this->assertSame('2026-10-25T07:00:00Z', $shifted->toIso8601ZuluString());
    }

    public function test_preferences_fill_in_the_defaults_and_keep_quiet_hours_off(): void
    {
        $defaults = NotificationPreferences::fromArray(null)->toArray();

        $this->assertSame([
            'appointment_reminders' => true,
            'reminder_minutes' => 1440,
            'task_digest' => true,
            'digest_time' => '08:00',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
        ], $defaults);

        $stored = NotificationPreferences::fromArray(['reminder_minutes' => 120, 'quiet_hours' => null]);

        $this->assertSame(120, $stored->reminderMinutes);
        $this->assertNull($stored->quietHours);
        $this->assertTrue($stored->taskDigest);
    }
}
