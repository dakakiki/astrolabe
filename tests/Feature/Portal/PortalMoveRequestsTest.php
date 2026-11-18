<?php

namespace Tests\Feature\Portal;

use App\Actions\Appointments\SaveAppointment;
use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Events\Appointments\AppointmentMoveConfirmed;
use App\Events\Appointments\AppointmentMoveDeclined;
use App\Events\Appointments\AppointmentMoveRequested;
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
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * Moving a confirmed appointment in a practice that confirms bookings by hand
 * (docs/spec/12, "Pomeranje kao zahtev"; the user's decision of 18 Nov 2026):
 * the client only asks — the appointment keeps its time, the time asked for is
 * held — and the astrologer confirms the move or declines it with a message.
 *
 * It is Monday 19 October 2026, 08:00 in Belgrade (UTC+2). Vega works Monday
 * to Friday 09:00–17:00 there (07:00–15:00 UTC). Ana's confirmed appointment
 * is on Wednesday at 08:00 UTC; she asks for Thursday at 10:00 UTC.
 */
class PortalMoveRequestsTest extends TestCase
{
    use BookingFixtures, InteractsWithPortal, RefreshDatabase;

    private const ASKED = '2026-10-22T10:00:00Z';

    private User $vega;

    private Client $ana;

    private PortalUser $user;

    private Service $service;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-19 06:00:00', 'UTC'));
        Event::fake([
            AppointmentRescheduled::class,
            AppointmentMoveRequested::class,
            AppointmentMoveConfirmed::class,
            AppointmentMoveDeclined::class,
        ]);
        Notification::fake();

        $this->vega = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create(['timezone' => 'Europe/Belgrade']);
        $this->ana = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($this->ana);
        $this->service = Service::factory()->bookable()->inWorkspace($this->vega->current_workspace_id)->create(['name' => 'Natal reading']);
        $this->bookingPolicy($this->vega, ['requires_confirmation' => true]);
        $this->workingWeek($this->vega, array_fill(1, 5, [['09:00', '17:00']]));
        $this->appointment = $this->appointmentFor('2026-10-21 08:00');
    }

    private function appointmentFor(string $start, array $attributes = []): Appointment
    {
        return Appointment::factory()
            ->forClient($this->ana, $this->vega)
            ->at($start)
            ->create($attributes + ['service_id' => $this->service->id]);
    }

    private function move(Appointment $appointment, string $start): TestResponse
    {
        return $this->portal('POST', "appointments/{$appointment->id}/reschedule", ['starts_at' => $start]);
    }

    private function ask(): void
    {
        $this->signInToPortal($this->user);
        $this->move($this->appointment, self::ASKED)->assertOk();
    }

    /** @return list<string> */
    private function freeToMove(Appointment $appointment): array
    {
        return $this->starts($this->portal('GET', "booking/slots?appointment_id={$appointment->id}")->assertOk());
    }

    private function answer(string $what, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->vega)->postJson("/api/v1/appointments/{$this->appointment->id}/move/{$what}", $data);
    }

    public function test_moving_a_confirmed_appointment_only_asks_and_holds_the_new_time(): void
    {
        $other = $this->appointmentFor('2026-10-23 08:00');
        $this->signInToPortal($this->user);

        $this->portal('GET', "booking/slots?appointment_id={$this->appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.move_needs_confirmation', true);
        $this->assertContains(self::ASKED, $this->freeToMove($other));

        $this->move($this->appointment, self::ASKED)
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-21T08:00:00Z')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.move_requested', ['starts_at' => self::ASKED, 'ends_at' => '2026-10-22T11:00:00Z']);

        $fresh = $this->appointment->fresh();
        $this->assertSame('2026-10-21 08:00', $fresh->starts_at->format('Y-m-d H:i'));
        $this->assertTrue($fresh->hasMoveRequest());
        $this->assertSame('2026-10-22 11:00', $fresh->proposed_ends_at->format('Y-m-d H:i'));
        $this->assertNotNull($fresh->proposed_at);

        $entry = ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::AppointmentMoveRequested)->sole();
        $this->assertSame('2026-10-21T08:00:00Z', $entry->metadata['from']);
        $this->assertSame(self::ASKED, $entry->metadata['to']);
        $this->assertSame('portal', $entry->metadata['source']);
        $this->assertSame(0, ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::AppointmentRescheduled)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalAppointmentMoveRequested)->count());
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::PortalAppointmentRescheduled)->count());
        Event::assertDispatched(AppointmentMoveRequested::class, fn (AppointmentMoveRequested $event) => $event->appointment->is($this->appointment));
        Event::assertNotDispatched(AppointmentRescheduled::class);

        // Both times are held: the one it has and the one asked for.
        $free = $this->freeToMove($other);
        $this->assertNotContains(self::ASKED, $free);
        $this->assertNotContains('2026-10-21T08:00:00Z', $free);

        // Asking for the same time again changes nothing; another time replaces the request.
        $this->move($this->appointment, self::ASKED)->assertOk();
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalAppointmentMoveRequested)->count());

        $this->move($this->appointment, '2026-10-22T12:00:00Z')
            ->assertOk()
            ->assertJsonPath('data.move_requested.starts_at', '2026-10-22T12:00:00Z');
        $this->assertContains(self::ASKED, $this->freeToMove($other));
        $this->assertNotContains('2026-10-22T12:00:00Z', $this->freeToMove($other));
        Event::assertDispatchedTimes(AppointmentMoveRequested::class, 2);
    }

    public function test_a_request_still_waiting_and_a_practice_without_confirmation_move_at_once(): void
    {
        $request = $this->appointmentFor('2026-10-23 08:00', ['status' => AppointmentStatus::Requested]);
        $this->signInToPortal($this->user);

        $this->move($request, '2026-10-23T10:00:00Z')
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-23T10:00:00Z')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.move_requested', null);
        $this->assertFalse($request->fresh()->hasMoveRequest());

        $this->bookingPolicy($this->vega, ['requires_confirmation' => false]);
        $this->portal('GET', "booking/slots?appointment_id={$this->appointment->id}")->assertJsonPath('data.move_needs_confirmation', false);
        $this->move($this->appointment, self::ASKED)
            ->assertOk()
            ->assertJsonPath('data.starts_at', self::ASKED)
            ->assertJsonPath('data.move_requested', null);

        Event::assertNotDispatched(AppointmentMoveRequested::class);
        Event::assertDispatchedTimes(AppointmentRescheduled::class, 2);
    }

    public function test_the_astrologer_confirms_the_move_from_the_dashboard_or_the_calendar(): void
    {
        $this->ask();

        $board = $this->actingAs($this->vega)->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame([$this->appointment->id], array_column($board->json('data.requests'), 'id'));
        $this->assertSame(self::ASKED, $board->json('data.requests.0.proposed_starts_at'));
        $this->assertSame(1, $board->json('data.counts.appointments_requested'));
        $this->actingAs($this->vega)->getJson("/api/v1/appointments/{$this->appointment->id}")
            ->assertJsonPath('data.proposed_starts_at', self::ASKED)
            ->assertJsonPath('data.proposed_ends_at', '2026-10-22T11:00:00Z');

        // The calendar draws the time asked for in the period it falls in, wherever the appointment is.
        $thursday = $this->actingAs($this->vega)->getJson('/api/v1/appointments?from=2026-10-22&to=2026-10-22')->assertOk();
        $this->assertSame([], $thursday->json('data'));
        $this->assertSame([$this->appointment->id], array_column($thursday->json('move_requests'), 'id'));
        $this->assertSame(self::ASKED, $thursday->json('move_requests.0.proposed_starts_at'));
        $wednesday = $this->actingAs($this->vega)->getJson('/api/v1/appointments?from=2026-10-21&to=2026-10-21');
        $this->assertSame([$this->appointment->id], array_column($wednesday->json('data'), 'id'));
        $this->assertSame([], $wednesday->json('move_requests'));
        $this->assertSame([], $this->actingAs($this->vega)->getJson('/api/v1/appointments?from=2026-10-22&to=2026-10-22&status=requested')->json('move_requests'));

        $this->answer('confirm')
            ->assertOk()
            ->assertJsonPath('data.starts_at', self::ASKED)
            ->assertJsonPath('data.ends_at', '2026-10-22T11:00:00Z')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.proposed_starts_at', null);

        $this->assertFalse($this->appointment->fresh()->hasMoveRequest());
        $moved = ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::AppointmentRescheduled)->sole();
        $this->assertSame(['2026-10-21T08:00:00Z', self::ASKED, 'portal', true], [
            $moved->metadata['from'], $moved->metadata['to'], $moved->metadata['source'], $moved->metadata['confirmed'],
        ]);
        Event::assertDispatched(AppointmentMoveConfirmed::class, fn (AppointmentMoveConfirmed $event) => $event->appointment->is($this->appointment)
            && $event->previousStart->toIso8601ZuluString() === '2026-10-21T08:00:00Z');
        // The client asked for it: no second "moved by the practice" notice.
        Event::assertNotDispatched(AppointmentRescheduled::class);

        // Answered once only; the dashboard has nothing left to answer.
        $this->answer('confirm')->assertStatus(409)->assertJsonPath('code', 'no_move_request');
        $this->answer('decline')->assertStatus(409)->assertJsonPath('code', 'no_move_request');
        $this->assertSame([], $this->actingAs($this->vega)->getJson('/api/v1/dashboard')->json('data.requests'));
    }

    public function test_the_astrologer_declines_the_move_with_a_message_and_the_time_is_free_again(): void
    {
        $other = $this->appointmentFor('2026-10-23 08:00');
        $this->ask();

        $this->answer('decline', ['reason' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->answer('decline', ['reason' => 'Thursday is fully booked, sorry.'])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-21T08:00:00Z')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.proposed_starts_at', null);

        $entry = ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::AppointmentMoveDeclined)->sole();
        $this->assertSame(self::ASKED, $entry->metadata['requested']);
        $this->assertSame('Thursday is fully booked, sorry.', $entry->metadata['reason']);
        Event::assertDispatched(AppointmentMoveDeclined::class, fn (AppointmentMoveDeclined $event) => $event->reason === 'Thursday is fully booked, sorry.'
            && $event->requestedStart->toIso8601ZuluString() === self::ASKED);

        $this->assertContains(self::ASKED, $this->freeToMove($other));
        $this->answer('decline')->assertStatus(409)->assertJsonPath('code', 'no_move_request');

        // Someone from another practice cannot answer it at all.
        $stranger = User::factory()->withWorkspace()->create();
        $this->appointment->forceFill(['proposed_starts_at' => self::ASKED, 'proposed_ends_at' => '2026-10-22T11:00:00Z'])->saveQuietly();
        $this->answer('confirm', as: $stranger)->assertNotFound();
        $this->assertTrue($this->appointment->fresh()->hasMoveRequest());
    }

    public function test_confirming_over_something_new_asks_first_and_a_passed_time_can_only_be_declined(): void
    {
        $this->ask();

        // Vega put something of her own at the time asked for meanwhile.
        $mine = Appointment::factory()
            ->forClient(Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(), $this->vega)
            ->at('2026-10-22 10:30', 30)
            ->create();

        $this->answer('confirm')
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.id', $mine->id);
        $this->assertTrue($this->appointment->fresh()->hasMoveRequest());

        $this->answer('confirm', ['allow_overlap' => true])->assertOk()->assertJsonPath('data.starts_at', self::ASKED);

        // A new request whose time comes before the answer.
        $this->move($this->appointment, '2026-10-21T12:00:00Z')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:30:00', 'UTC'));

        $this->answer('confirm')->assertStatus(409)->assertJsonPath('code', 'move_passed');
        $this->answer('decline')->assertOk()->assertJsonPath('data.starts_at', self::ASKED);
    }

    public function test_the_request_ends_when_the_practice_moves_the_appointment_or_the_client_cancels_it(): void
    {
        $this->ask();

        $this->inPracticeOf($this->vega, fn () => app(SaveAppointment::class)->handle($this->appointment->fresh(), [
            'starts_at' => '2026-10-21T14:00',
            'timezone' => 'Europe/Belgrade',
        ]));
        $this->assertFalse($this->appointment->fresh()->hasMoveRequest());
        $this->assertSame([], $this->actingAs($this->vega)->getJson('/api/v1/dashboard')->json('data.requests'));

        $this->move($this->appointment, self::ASKED)->assertOk()->assertJsonPath('data.move_requested.starts_at', self::ASKED);
        $this->portal('POST', "appointments/{$this->appointment->id}/cancel")->assertOk();

        $fresh = $this->appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertFalse($fresh->hasMoveRequest());
    }
}
