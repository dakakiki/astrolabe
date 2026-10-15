<?php

namespace Tests\Feature\Notifications;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Booking\CancelPortalAppointment;
use App\Actions\Booking\RescheduleAppointment;
use App\Enums\ActivityType;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Notifications\ClientAppointmentCancelled;
use App\Notifications\ClientAppointmentMoved;
use App\Notifications\PortalBookingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The practice and the client change the same appointment at once (Phase 9b):
 * the calendar's request has read the appointment, the client moves or cancels
 * it in the portal, then the calendar's change arrives. The practice's cancel
 * and move read the row again under a lock and work on what it is now, so
 * nobody gets contradictory emails and the client's change is not overwritten.
 */
class AppointmentRacesTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        Notification::fake();
    }

    /**
     * Runs `$change` right after the calendar's next request has read the
     * appointment (route binding), before its checks and its action.
     */
    private function meanwhile(callable $change): void
    {
        $done = false;

        Appointment::retrieved(function () use (&$done, $change) {
            if (! $done) {
                $done = true;
                $change();
            }
        });
    }

    private function clientCancels(Appointment $appointment): void
    {
        $this->inPractice(fn () => app(CancelPortalAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $appointment,
            'Feeling unwell',
        ));
    }

    /** @return list<string> */
    private function timeline(): array
    {
        return ActivityEvent::query()
            ->withoutGlobalScopes()
            ->where('client_id', $this->client->id)
            ->where('event_type', 'like', 'appointment_%')
            ->orderBy('id')
            ->pluck('event_type')
            ->map(fn ($type) => $type->value)
            ->all();
    }

    public function test_the_practice_cannot_cancel_what_the_client_has_just_cancelled(): void
    {
        $appointment = $this->book();
        Notification::fake();
        $this->meanwhile(fn () => $this->clientCancels($appointment));

        $this->actingAs($this->astrologer)
            ->postJson("/api/v1/appointments/{$appointment->id}/cancel", ['reason' => 'Internal: double booked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => __('appointments.not_cancellable')]);

        // The client's cancellation stands, once, and each side heard about it once.
        $row = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $row->status);
        $this->assertSame(AppointmentActor::Client, $row->cancelled_by);
        $this->assertSame('Feeling unwell', $row->cancellation_reason);
        $this->assertSame(['appointment_cancelled'], $this->timeline());

        Notification::assertSentToTimes($this->portalUser, ClientAppointmentCancelled::class, 1);
        Notification::assertSentTo($this->portalUser, fn (ClientAppointmentCancelled $email) => $email->byClient);
        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 1);
    }

    public function test_the_cancel_action_reads_the_row_again_however_it_was_loaded(): void
    {
        $appointment = $this->book();
        $stale = $this->inPractice(fn () => Appointment::query()->findOrFail($appointment->id));
        $this->clientCancels($appointment);
        Notification::fake();

        try {
            $this->inPractice(fn () => app(CancelAppointment::class)->handle($stale, 'Internal: double booked'));
            $this->fail('A practice cancel of a cancelled appointment must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('appointments.not_cancellable')], $exception->errors()['status']);
        }

        $this->assertSame(AppointmentActor::Client, $appointment->fresh()->cancelled_by);
        Notification::assertNothingSent();
    }

    public function test_a_practice_move_after_the_client_cancelled_tells_nobody_it_was_moved(): void
    {
        $appointment = $this->book();
        Notification::fake();
        $this->meanwhile(fn () => $this->clientCancels($appointment));

        $this->actingAs($this->astrologer)
            ->patchJson("/api/v1/appointments/{$appointment->id}", ['starts_at' => '2026-10-06T15:00', 'timezone' => 'Europe/Belgrade'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'client');

        $row = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $row->status);
        $this->assertSame(AppointmentActor::Client, $row->cancelled_by);
        $this->assertNull($row->client_remind_at);

        // The client was told only what they did; nobody hears of a move of a cancelled appointment.
        Notification::assertNotSentTo($this->portalUser, ClientAppointmentMoved::class);
        Notification::assertSentToTimes($this->portalUser, ClientAppointmentCancelled::class, 1);
        Notification::assertSentTo($this->portalUser, fn (ClientAppointmentCancelled $email) => $email->byClient);
        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 1);
    }

    public function test_a_practice_move_after_the_client_moved_it_starts_from_where_it_is_now(): void
    {
        $appointment = $this->book();
        Notification::fake();
        $this->meanwhile(fn () => $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $appointment,
            CarbonImmutable::parse('2026-10-06 14:00', 'UTC'),
        )));

        $this->actingAs($this->astrologer)
            ->patchJson("/api/v1/appointments/{$appointment->id}", ['starts_at' => '2026-10-07T15:00', 'timezone' => 'Europe/Belgrade'])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-07T13:00:00Z');

        $moves = ActivityEvent::query()
            ->withoutGlobalScopes()
            ->where('event_type', ActivityType::AppointmentRescheduled)
            ->orderBy('id')
            ->get()
            ->map(fn (ActivityEvent $entry) => [$entry->metadata['from'], $entry->metadata['to']])
            ->all();
        $this->assertSame([
            ['2026-10-05T13:00:00Z', '2026-10-06T14:00:00Z'],
            ['2026-10-06T14:00:00Z', '2026-10-07T13:00:00Z'],
        ], $moves);

        Notification::assertSentToTimes($this->portalUser, ClientAppointmentMoved::class, 2);
        Notification::assertSentTo($this->portalUser, fn (ClientAppointmentMoved $email) => ! $email->byClient
            && $email->previousStartsAt === '2026-10-06T14:00:00Z');
    }
}
