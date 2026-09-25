<?php

namespace Tests\Feature\Notifications;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Email reminders before appointments (docs/spec/10, "Notifikacije"): 24 hours
 * before by default, another lead time or none per appointment, outside quiet
 * hours, moved with the appointment, stopped by cancelling, sent once.
 */
class AppointmentRemindersTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday 2 October 2026, 10:00 in Belgrade.
        $this->travelTo('2026-10-02 08:00:00');

        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->client = Client::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'first_name' => 'Ana',
            'last_name' => 'Marković',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function book(array $attributes = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/v1/appointments', $attributes + [
            'client_id' => $this->client->id,
            'starts_at' => '2026-10-05T15:00',
            'timezone' => 'Europe/Belgrade',
            'duration_minutes' => 60,
        ]);
    }

    private function sendDue(): void
    {
        $this->artisan('notifications:send-reminders')->assertSuccessful();
    }

    public function test_a_new_appointment_gets_the_usual_reminder_a_day_before(): void
    {
        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.reminder_minutes', 1440)
            ->assertJsonPath('data.remind_at', '2026-10-04T13:00:00Z')
            ->assertJsonPath('data.reminder_sent_at', null);
    }

    public function test_the_lead_time_is_chosen_per_appointment_or_left_out(): void
    {
        $this->book(['reminder_minutes' => 120])
            ->assertCreated()
            ->assertJsonPath('data.remind_at', '2026-10-05T11:00:00Z');

        $this->book(['starts_at' => '2026-10-06T15:00', 'reminder_minutes' => null])
            ->assertCreated()
            ->assertJsonPath('data.reminder_minutes', null)
            ->assertJsonPath('data.remind_at', null);

        $this->book(['starts_at' => '2026-10-07T15:00', 'reminder_minutes' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reminder_minutes');
    }

    public function test_the_usual_lead_time_comes_from_the_astrologers_preferences(): void
    {
        $this->user->forceFill(['notification_preferences' => ['reminder_minutes' => 60]])->save();

        $this->book()->assertCreated()
            ->assertJsonPath('data.reminder_minutes', 60)
            ->assertJsonPath('data.remind_at', '2026-10-05T12:00:00Z');
    }

    public function test_a_reminder_that_falls_in_quiet_hours_waits_for_the_morning(): void
    {
        // 07:00 on Monday, a day before: Sunday 07:00 is quiet (22:00–08:00), so Sunday 08:00.
        $this->book(['starts_at' => '2026-10-05T07:00'])
            ->assertJsonPath('data.remind_at', '2026-10-04T06:00:00Z');

        // An hour before 07:30 is 06:30, and 08:00 would be too late: Sunday 21:59 instead.
        $this->book(['starts_at' => '2026-10-06T07:30', 'reminder_minutes' => 60])
            ->assertJsonPath('data.remind_at', '2026-10-05T19:59:00Z');
    }

    public function test_a_reminder_whose_time_has_passed_is_not_planned(): void
    {
        // This afternoon, a day's notice: nothing to remind of.
        $this->book(['starts_at' => '2026-10-02T15:00'])
            ->assertCreated()
            ->assertJsonPath('data.reminder_minutes', 1440)
            ->assertJsonPath('data.remind_at', null);
    }

    public function test_moving_the_appointment_moves_the_reminder_and_cancelling_stops_it(): void
    {
        $id = $this->book()->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['starts_at' => '2026-10-08T09:30'])
            ->assertOk()
            ->assertJsonPath('data.remind_at', '2026-10-07T07:30:00Z');

        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['reminder_minutes' => 30])
            ->assertJsonPath('data.remind_at', '2026-10-08T07:00:00Z');

        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'Client is ill'])
            ->assertOk()
            ->assertJsonPath('data.remind_at', null);

        // Scheduled again: the reminder comes back.
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['status' => 'scheduled'])
            ->assertJsonPath('data.remind_at', '2026-10-08T07:00:00Z');
    }

    public function test_due_reminders_are_sent_once_to_the_astrologer_without_client_details(): void
    {
        Notification::fake();
        $appointment = Appointment::query()->withoutGlobalScopes()->findOrFail($this->book()->json('data.id'));

        $this->sendDue();
        Notification::assertNothingSent();

        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDue();
        $this->sendDue();

        Notification::assertSentToTimes($this->user, AppointmentReminder::class, 1);
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);

        Notification::assertSentTo($this->user, function (AppointmentReminder $reminder) use ($appointment) {
            $mail = $reminder->toMail($this->user);
            $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

            $this->assertStringContainsString('Monday, October 5, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade)', $text);
            $this->assertStringNotContainsString('Ana', $text);
            $this->assertStringNotContainsString('Marković', $text);
            $this->assertSame(url('/calendar').'?date=2026-10-05&appointment='.$appointment->id, $mail->actionUrl);

            return $reminder->shouldSend($this->user, 'mail');
        });
    }

    public function test_cancelled_moved_or_past_appointments_send_nothing(): void
    {
        Notification::fake();

        $cancelled = $this->book()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$cancelled}/cancel", ['reason' => 'Moved abroad']);

        $moved = $this->book(['starts_at' => '2026-10-05T17:00'])->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$moved}", ['starts_at' => '2026-10-09T17:00']);

        $this->travelTo('2026-10-04 16:00:00');
        $this->sendDue();

        Notification::assertNothingSent();
        $this->assertNull(Appointment::query()->withoutGlobalScopes()->find($moved)->reminder_sent_at);
    }

    public function test_a_reminder_queued_before_the_appointment_moved_is_dropped(): void
    {
        $appointment = Appointment::query()->withoutGlobalScopes()->findOrFail($this->book()->json('data.id'));
        $reminder = AppointmentReminder::for($appointment);

        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$appointment->id}", ['starts_at' => '2026-10-05T18:00']);

        $this->assertFalse($reminder->shouldSend($this->user, 'mail'));
        $this->assertTrue(AppointmentReminder::for($appointment->fresh())->shouldSend($this->user, 'mail'));
        $this->assertFalse(AppointmentReminder::for($appointment->fresh())->shouldSend($this->memberOf($this->user), 'mail'));
    }

    public function test_a_moved_appointment_is_reminded_again(): void
    {
        Notification::fake();
        $id = $this->book()->json('data.id');

        $this->travelTo('2026-10-04 13:01:00');
        $this->sendDue();

        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['starts_at' => '2026-10-09T15:00'])
            ->assertJsonPath('data.remind_at', '2026-10-08T13:00:00Z')
            ->assertJsonPath('data.reminder_sent_at', null);

        $this->travelTo('2026-10-08 13:00:00');
        $this->sendDue();

        Notification::assertSentToTimes($this->user, AppointmentReminder::class, 2);
    }

    public function test_switching_reminders_off_or_changing_the_zone_plans_them_again(): void
    {
        $id = $this->book()->json('data.id');
        $remindAt = fn () => Appointment::query()->withoutGlobalScopes()->find($id)->remind_at?->toIso8601ZuluString();

        $this->user->forceFill(['notification_preferences' => ['appointment_reminders' => false]])->save();
        $this->assertNull($remindAt());

        $this->user->forceFill(['notification_preferences' => ['appointment_reminders' => true]])->save();
        $this->assertSame('2026-10-04T13:00:00Z', $remindAt());

        // A day before 15:00 Belgrade is 22:00 in Tokyo, inside quiet hours: 08:00 Tokyo.
        $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        $this->assertSame('2026-10-04T23:00:00Z', $remindAt());
    }

    public function test_the_reminder_goes_to_the_astrologer_who_runs_the_appointment(): void
    {
        Notification::fake();
        $member = $this->memberOf($this->user);
        $member->forceFill(['timezone' => 'America/New_York', 'notification_preferences' => ['reminder_minutes' => 60]])->save();

        $this->book(['assigned_user_id' => $member->id])
            ->assertJsonPath('data.reminder_minutes', 60)
            ->assertJsonPath('data.remind_at', '2026-10-05T12:00:00Z');

        $this->travelTo('2026-10-05 12:00:00');
        $this->sendDue();

        Notification::assertNotSentTo($this->user, AppointmentReminder::class);
        Notification::assertSentTo($member, function (AppointmentReminder $reminder) use ($member) {
            return str_contains(implode(' ', $reminder->toMail($member)->introLines), '9:00 AM – 10:00 AM (America/New_York)');
        });
    }

    public function test_reminders_are_sent_in_every_workspace(): void
    {
        Notification::fake();
        $this->book();

        $other = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $otherClient = Client::factory()->inWorkspace($other->current_workspace_id)->create();
        $this->actingAs($other)->postJson('/api/v1/appointments', [
            'client_id' => $otherClient->id,
            'starts_at' => '2026-10-05T15:00',
            'duration_minutes' => 60,
        ])->assertCreated();

        $this->travelTo('2026-10-04 13:00:00');
        $this->sendDue();

        Notification::assertSentToTimes($this->user, AppointmentReminder::class, 1);
        Notification::assertSentToTimes($other, AppointmentReminder::class, 1);
    }
}
