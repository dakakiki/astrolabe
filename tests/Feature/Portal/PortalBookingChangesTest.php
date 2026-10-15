<?php

namespace Tests\Feature\Portal;

use App\Enums\ActivityType;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Events\Appointments\AppointmentBooked;
use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PortalUser;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * The client moves and cancels their own appointments in the portal (docs/spec/12,
 * "Zakazivanje (9b)"; acceptance criterion 9b 4): only until the practice's
 * deadline before the start (afterwards the portal says to contact the
 * practice), only to a time that is really free — the appointment's own time
 * counts as free for itself — and only while it is still open.
 *
 * It is Monday 19 October 2026, 08:00 in Belgrade (UTC+2). The owner works
 * Monday to Friday 09:00–17:00 there (07:00–15:00 UTC this week). Ana's
 * appointment is on Wednesday at 10:00 Belgrade time (08:00 UTC), so by default
 * she may change it until Tuesday 08:00 UTC.
 */
class PortalBookingChangesTest extends TestCase
{
    use AddsWorkspaceMembers, BookingFixtures, InteractsWithPortal, RefreshDatabase;

    private User $vega;

    private Client $ana;

    private PortalUser $user;

    private Service $service;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-19 06:00:00', 'UTC'));
        Event::fake([AppointmentBooked::class, AppointmentRescheduled::class, AppointmentCancelled::class]);
        Notification::fake();

        $this->vega = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->ana = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($this->ana);
        $this->service = Service::factory()->bookable()->inWorkspace($this->vega->current_workspace_id)->create(['name' => 'Natal reading']);
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, array_fill(1, 5, [['09:00', '17:00']]));
        $this->appointment = $this->appointmentFor($this->ana, '2026-10-21 08:00');
    }

    private function appointmentFor(Client $client, string $start, int $minutes = 60, array $attributes = [], ?User $astrologer = null): Appointment
    {
        return Appointment::factory()
            ->forClient($client, $astrologer ?? $this->vega)
            ->at($start, $minutes)
            ->create($attributes + ['service_id' => $this->service->id]);
    }

    private function move(Appointment $appointment, string $start): TestResponse
    {
        return $this->portal('POST', "appointments/{$appointment->id}/reschedule", ['starts_at' => $start]);
    }

    private function cancel(Appointment $appointment, array $data = []): TestResponse
    {
        return $this->portal('POST', "appointments/{$appointment->id}/cancel", $data);
    }

    private function slotsToMove(Appointment $appointment): TestResponse
    {
        return $this->portal('GET', "booking/slots?appointment_id={$appointment->id}");
    }

    /** @return array<string, mixed> Ana's appointment as the upcoming list shows it */
    private function listed(Appointment $appointment, string $when = 'upcoming'): array
    {
        $item = collect($this->portal('GET', "appointments?when={$when}")->assertOk()->json('data'))->firstWhere('id', $appointment->id);
        $this->assertNotNull($item, "Appointment {$appointment->id} is not in the {$when} list.");

        return $item;
    }

    public function test_moving_offers_the_appointments_own_length_and_time_and_keeps_its_status(): void
    {
        $long = $this->appointmentFor($this->ana, '2026-10-22 08:00', 90);
        $this->signInToPortal($this->user);

        $item = $this->listed($this->appointment);
        $this->assertTrue($item['can_reschedule']);
        $this->assertSame('2026-10-20T08:00:00Z', $item['reschedule_until']);

        // Its own time is free for itself; for anyone else it is taken, with its buffer.
        $own = $this->starts($this->slotsToMove($this->appointment)->assertOk()->assertJsonPath('data.duration_minutes', 60));
        $others = $this->starts($this->portal('GET', "booking/slots?service_id={$this->service->id}"));
        foreach (['2026-10-21T07:30:00Z', '2026-10-21T08:30:00Z'] as $start) {
            $this->assertContains($start, $own);
            $this->assertNotContains($start, $others);
        }

        // Its current start would be no move at all, so it is not offered.
        $this->assertNotContains('2026-10-21T08:00:00Z', $own);

        // The 90-minute one is offered 90-minute times: 15:00 would end after the working day.
        $longSlots = $this->slotsToMove($long)->assertJsonPath('data.duration_minutes', 90)->json('data.slots');
        $this->assertSame(['starts_at' => '2026-10-20T07:00:00Z', 'ends_at' => '2026-10-20T08:30:00Z'], $longSlots[0]);
        $this->assertContains('2026-10-20T13:30:00Z', array_column($longSlots, 'starts_at'));
        $this->assertNotContains('2026-10-20T14:00:00Z', array_column($longSlots, 'starts_at'));

        $this->move($long, '2026-10-23T12:30:00Z')
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-23T12:30:00Z')
            ->assertJsonPath('data.ends_at', '2026-10-23T14:00:00Z')
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.reschedule_until', '2026-10-22T12:30:00Z');

        $long->refresh();
        $this->assertSame('2026-10-23 12:30', $long->starts_at->format('Y-m-d H:i'));
        $this->assertSame('Europe/Belgrade', $long->timezone);

        // On the timeline as moved in the portal; in the audit as the client's; the astrologer hears.
        $entry = ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::AppointmentRescheduled)->sole();
        $this->assertSame('portal', $entry->metadata['source']);
        $this->assertSame('2026-10-22T08:00:00Z', $entry->metadata['from']);
        $this->assertSame('2026-10-23T12:30:00Z', $entry->metadata['to']);
        $this->assertNull($entry->created_by);
        $audit = AuditLog::query()->where('event', AuditEvent::PortalAppointmentRescheduled)->sole();
        $this->assertSame([$this->user->id, $long->id, null], [$audit->portal_user_id, $audit->subject_id, $audit->user_id]);
        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $event) => $event->appointment->is($long)
            && $event->by === AppointmentActor::Client
            && $event->previousStart->equalTo(CarbonImmutable::parse('2026-10-22 08:00', 'UTC')));

        // A request waiting for the practice moves and stays a request.
        $request = $this->appointmentFor($this->ana, '2026-10-26 09:00', attributes: ['status' => AppointmentStatus::Requested]);
        $this->move($request, '2026-10-27T13:00:00Z')->assertOk()->assertJsonPath('data.status', 'requested');
        $this->assertSame(AppointmentStatus::Requested, $request->fresh()->status);
    }

    public function test_moving_to_a_taken_time_answers_with_fresh_free_times(): void
    {
        $boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create();
        $this->appointmentFor($boris, '2026-10-22 09:00');
        $this->signInToPortal($this->user);

        $conflict = $this->move($this->appointment, '2026-10-22T09:00:00Z')
            ->assertStatus(409)
            ->assertJsonPath('code', 'slot_taken');

        $fresh = $this->starts($conflict, 'slots');
        $this->assertSame($this->starts($this->slotsToMove($this->appointment)), $fresh);
        // Boris's time and its buffers are not offered; Ana's own time is, all but its current start.
        foreach (['2026-10-22T08:00:00Z', '2026-10-22T09:00:00Z', '2026-10-22T10:00:00Z', '2026-10-21T08:00:00Z'] as $taken) {
            $this->assertNotContains($taken, $fresh);
        }
        $this->assertContains('2026-10-21T07:30:00Z', $fresh);
        $this->assertContains('2026-10-21T08:30:00Z', $fresh);
        $this->assertContains('2026-10-22T10:30:00Z', $fresh);

        $this->assertSame('2026-10-21 08:00', $this->appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::PortalAppointmentRescheduled)->count());
        Event::assertNotDispatched(AppointmentRescheduled::class);

        // Too soon or off the grid is no free time either.
        $this->move($this->appointment, '2026-10-19T13:00:00Z')->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->move($this->appointment, '2026-10-21T08:10:00Z')->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->move($this->appointment, '2026-10-21 10:00')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_moving_and_cancelling_end_at_their_own_deadlines(): void
    {
        // Moving until 48 hours before, cancelling until 12 hours before.
        $this->bookingPolicy($this->vega, ['client_reschedule_until_hours' => 48, 'client_cancel_until_hours' => 12]);
        $this->signInToPortal($this->user);

        $item = $this->listed($this->appointment);
        $this->assertSame([true, true, '2026-10-19T08:00:00Z', '2026-10-20T20:00:00Z'], [
            $item['can_reschedule'], $item['can_cancel'], $item['reschedule_until'], $item['cancel_until'],
        ]);

        // One second before the deadline it still works…
        $this->travelTo(CarbonImmutable::parse('2026-10-19 07:59:59', 'UTC'));
        $this->slotsToMove($this->appointment)->assertOk();

        // …at the deadline it does not, and the portal says until when it was possible.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00:00', 'UTC'));
        $item = $this->listed($this->appointment);
        $this->assertFalse($item['can_reschedule']);
        $this->assertSame('2026-10-19T08:00:00Z', $item['reschedule_until']);
        $this->assertTrue($item['can_cancel']);
        $this->slotsToMove($this->appointment)->assertStatus(409)->assertJsonPath('code', 'change_too_late')->assertJsonPath('message', __('booking.change_too_late'));
        $this->move($this->appointment, '2026-10-23T08:00:00Z')->assertStatus(409)->assertJsonPath('code', 'change_too_late');
        $this->assertSame('2026-10-21 08:00', $this->appointment->fresh()->starts_at->format('Y-m-d H:i'));

        $this->travelTo(CarbonImmutable::parse('2026-10-20 20:00:00', 'UTC'));
        $this->assertFalse($this->listed($this->appointment)['can_cancel']);
        $this->cancel($this->appointment)->assertStatus(409)->assertJsonPath('code', 'change_too_late');
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment->fresh()->status);
    }

    public function test_without_hours_in_the_policy_the_client_changes_nothing_here(): void
    {
        $this->bookingPolicy($this->vega, ['client_reschedule_until_hours' => null, 'client_cancel_until_hours' => null]);
        $this->signInToPortal($this->user);

        $item = $this->listed($this->appointment);
        $this->assertSame([false, false, null, null], [$item['can_reschedule'], $item['can_cancel'], $item['reschedule_until'], $item['cancel_until']]);

        $this->slotsToMove($this->appointment)->assertStatus(409)->assertJsonPath('code', 'change_too_late');
        $this->move($this->appointment, '2026-10-23T08:00:00Z')->assertStatus(409)->assertJsonPath('code', 'change_too_late');
        $this->cancel($this->appointment)->assertStatus(409)->assertJsonPath('code', 'change_too_late');
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment->fresh()->status);
    }

    public function test_cancelling_frees_the_time_and_records_that_the_client_did(): void
    {
        $request = $this->appointmentFor($this->ana, '2026-10-23 08:00', attributes: ['status' => AppointmentStatus::Requested]);
        $this->signInToPortal($this->user);
        $this->assertNotContains('2026-10-21T08:00:00Z', $this->starts($this->portal('GET', "booking/slots?service_id={$this->service->id}")));

        $response = $this->cancel($this->appointment, ['reason' => 'I have a fever, sorry.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.upcoming', false)
            ->assertJsonPath('data.cancelled_by', 'client')
            ->assertJsonPath('data.declined', false)
            ->assertJsonPath('data.decline_reason', null)
            ->assertJsonPath('data.can_reschedule', false)
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.location_details', null);
        $this->assertStringNotContainsString('fever', $response->getContent());

        $cancelled = $this->appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        $this->assertSame(AppointmentActor::Client, $cancelled->cancelled_by);
        $this->assertSame('I have a fever, sorry.', $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);

        $entry = ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::AppointmentCancelled)->sole();
        $this->assertSame('portal', $entry->metadata['source']);
        $audit = AuditLog::query()->where('event', AuditEvent::PortalAppointmentCancelled)->sole();
        $this->assertSame([$this->user->id, $this->appointment->id], [$audit->portal_user_id, $audit->subject_id]);
        $this->assertStringNotContainsString('fever', (string) json_encode($audit->properties));
        Event::assertDispatched(AppointmentCancelled::class, fn (AppointmentCancelled $event) => $event->appointment->is($this->appointment) && $event->by === AppointmentActor::Client);

        // The time is free again, and the appointment is in the past list as cancelled by the client.
        $this->assertContains('2026-10-21T08:00:00Z', $this->starts($this->portal('GET', "booking/slots?service_id={$this->service->id}")));
        $this->assertSame('client', $this->listed($this->appointment, 'past')['cancelled_by']);
        $this->assertStringNotContainsString('fever', $this->portal('GET', 'appointments?when=past')->getContent());

        // A request is withdrawn the same way, without a message.
        $this->cancel($request)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($request->fresh()->cancellation_reason);

        $this->cancel($this->appointment, ['reason' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_what_is_no_longer_open_cannot_be_changed(): void
    {
        $held = $this->appointmentFor($this->ana, '2026-10-12 08:00', attributes: ['status' => AppointmentStatus::Completed]);
        $missed = $this->appointmentFor($this->ana, '2026-10-14 08:00', attributes: ['status' => AppointmentStatus::NoShow]);
        $cancelled = $this->appointmentFor($this->ana, '2026-10-28 08:00', attributes: [
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => AppointmentActor::Practice,
            'cancellation_reason' => 'Astrologer is ill',
        ]);
        $this->signInToPortal($this->user);

        foreach ([$held, $missed, $cancelled] as $appointment) {
            $this->slotsToMove($appointment)->assertStatus(409)->assertJsonPath('code', 'not_changeable');
            $this->move($appointment, '2026-10-23T08:00:00Z')->assertStatus(409)->assertJsonPath('code', 'not_changeable');
            $this->cancel($appointment)->assertStatus(409)->assertJsonPath('code', 'not_changeable');

            $item = $this->listed($appointment, 'past');
            $this->assertSame([false, false, null, null], [$item['can_reschedule'], $item['can_cancel'], $item['reschedule_until'], $item['cancel_until']]);
        }

        $this->assertSame(AppointmentStatus::Completed, $held->fresh()->status);
        $this->assertSame('Astrologer is ill', $cancelled->fresh()->cancellation_reason);
        $this->assertSame(0, AuditLog::query()->whereIn('event', [AuditEvent::PortalAppointmentRescheduled, AuditEvent::PortalAppointmentCancelled])->count());
    }

    public function test_a_declined_request_carries_the_practices_message_and_a_cancellation_does_not(): void
    {
        $declined = $this->appointmentFor($this->ana, '2026-10-27 08:00', attributes: [
            'status' => AppointmentStatus::Cancelled,
            'booking_source' => 'portal',
            'cancelled_at' => now(),
            'cancelled_by' => AppointmentActor::Practice,
            'declined_at' => now(),
            'cancellation_reason' => 'That week is full — any day in November works.',
        ]);
        $byPractice = $this->appointmentFor($this->ana, '2026-10-28 08:00', attributes: [
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => AppointmentActor::Practice,
            'cancellation_reason' => 'Astrologer is ill',
        ]);
        $this->signInToPortal($this->user);

        $item = $this->listed($declined, 'past');
        $this->assertSame(['practice', true, 'That week is full — any day in November works.', true], [
            $item['cancelled_by'], $item['declined'], $item['decline_reason'], $item['booked_in_portal'],
        ]);

        $item = $this->listed($byPractice, 'past');
        $this->assertSame(['practice', false, null, false], [$item['cancelled_by'], $item['declined'], $item['decline_reason'], $item['booked_in_portal']]);
        $this->assertStringNotContainsString('Astrologer is ill', $this->portal('GET', 'appointments?when=past')->getContent());
    }

    public function test_with_booking_switched_off_nothing_is_changed_here(): void
    {
        $this->bookingPolicy($this->vega, ['enabled' => false]);
        $this->signInToPortal($this->user);

        $item = $this->listed($this->appointment);
        $this->assertSame([false, false, null, null], [$item['can_reschedule'], $item['can_cancel'], $item['reschedule_until'], $item['cancel_until']]);

        $this->slotsToMove($this->appointment)->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->move($this->appointment, '2026-10-23T08:00:00Z')->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->cancel($this->appointment)->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment->fresh()->status);
    }

    public function test_an_appointment_with_another_astrologer_is_cancelled_here_but_not_moved(): void
    {
        $member = $this->memberOf($this->vega);
        $withMember = $this->appointmentFor($this->ana, '2026-10-22 08:00', astrologer: $member);
        $this->signInToPortal($this->user);

        // The portal only knows the booking astrologer's free times.
        $item = $this->listed($withMember);
        $this->assertSame([false, null, true], [$item['can_reschedule'], $item['reschedule_until'], $item['can_cancel']]);
        $this->slotsToMove($withMember)->assertStatus(409)->assertJsonPath('code', 'not_changeable');
        $this->move($withMember, '2026-10-23T08:00:00Z')->assertStatus(409)->assertJsonPath('code', 'not_changeable');

        $this->cancel($withMember)->assertOk()->assertJsonPath('data.cancelled_by', 'client');
    }

    public function test_a_repeated_move_with_the_same_key_moves_once(): void
    {
        $this->signInToPortal($this->user);

        $first = $this->portalWithKey('portal-move-0001', 'POST', "appointments/{$this->appointment->id}/reschedule", ['starts_at' => '2026-10-23T08:00:00Z'])->assertOk();
        $this->portalWithKey('portal-move-0001', 'POST', "appointments/{$this->appointment->id}/reschedule", ['starts_at' => '2026-10-23T08:00:00Z'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.starts_at', $first->json('data.starts_at'));

        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalAppointmentRescheduled)->count());
        Event::assertDispatchedTimes(AppointmentRescheduled::class, 1);
    }
}
