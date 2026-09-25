<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\TestEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Settings → Notifications (docs/spec/09): the person's own preferences.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-02 08:00:00');
        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
    }

    public function test_the_defaults_come_with_the_signed_in_user(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.notification_preferences', [
                'appointment_reminders' => true,
                'reminder_minutes' => 1440,
                'task_digest' => true,
                'digest_time' => '08:00',
                'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
            ]);
    }

    public function test_preferences_are_saved_and_only_the_keys_sent_change(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', [
            'reminder_minutes' => 120,
            'digest_time' => '07:15',
            'quiet_hours' => ['start' => '23:00', 'end' => '07:00'],
        ])->assertOk()
            ->assertJsonPath('data.notification_preferences.reminder_minutes', 120)
            ->assertJsonPath('data.notification_preferences.appointment_reminders', true)
            ->assertJsonPath('data.notification_preferences.digest_time', '07:15')
            ->assertJsonPath('data.notification_preferences.quiet_hours', ['start' => '23:00', 'end' => '07:00']);

        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', ['quiet_hours' => null])
            ->assertOk()
            ->assertJsonPath('data.notification_preferences.quiet_hours', null)
            ->assertJsonPath('data.notification_preferences.reminder_minutes', 120);

        $this->assertSame('2026-10-03T05:15:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());
    }

    public function test_invalid_preferences_are_rejected(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', [
            'reminder_minutes' => 45,
            'task_digest' => 'sometimes',
            'digest_time' => '8 am',
            'quiet_hours' => ['start' => '22:00', 'end' => '22:00'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reminder_minutes', 'task_digest', 'digest_time', 'quiet_hours.end']);

        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', [
            'quiet_hours' => ['start' => '22:00'],
        ])->assertUnprocessable()->assertJsonValidationErrors('quiet_hours.end');
    }

    public function test_the_morning_email_cannot_fall_in_quiet_hours(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', ['digest_time' => '07:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['digest_time' => 'Choose a time outside your quiet hours.']);

        // Fine once the email is off, or the quiet ends earlier.
        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', ['digest_time' => '07:00', 'task_digest' => false])
            ->assertOk();
        $this->actingAs($this->user)->putJson('/api/v1/notification-preferences', [
            'task_digest' => true,
            'quiet_hours' => ['start' => '22:00', 'end' => '06:30'],
        ])->assertOk();
    }

    public function test_a_test_email_goes_to_the_person_and_is_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($this->user)->postJson('/api/v1/notification-preferences/test')
                ->assertStatus(202)
                ->assertJsonPath('data.queued', true);
        }

        $this->actingAs($this->user)->postJson('/api/v1/notification-preferences/test')->assertTooManyRequests();

        Notification::assertSentToTimes($this->user, TestEmail::class, 3);
        Notification::assertSentTo($this->user, function (TestEmail $email) {
            $this->assertStringContainsString('(Europe/Belgrade)', implode(' ', $email->toMail($this->user)->introLines));

            return true;
        });
    }

    public function test_preferences_need_a_signed_in_verified_user(): void
    {
        $this->putJson('/api/v1/notification-preferences', ['reminder_minutes' => 60])->assertUnauthorized();

        $unverified = User::factory()->withWorkspace()->unverified()->create();
        $this->actingAs($unverified)->putJson('/api/v1/notification-preferences', ['reminder_minutes' => 60])
            ->assertForbidden();
    }
}
