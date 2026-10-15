<?php

namespace Tests\Feature\Booking;

use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentConfirmed;
use App\Events\Appointments\AppointmentDeclined;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * The astrologer's side of portal bookings (Phase 9b, docs/spec/12): requests
 * are confirmed or declined in the calendar, and the client hears about a move
 * or a cancellation unless the astrologer says not to.
 */
class BookingRequestsTest extends TestCase
{
    use AddsWorkspaceMembers, InteractsWithPortal, RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'UTC'));
        $this->owner = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->client = Client::factory()->inWorkspace($this->owner->current_workspace_id)->create([
            'first_name' => 'Ana',
            'email' => 'ana@example.com',
        ]);
    }

    /**
     * A booking from the portal, waiting for the astrologer by default.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = [], string $utcStart = '2026-10-20 09:00'): Appointment
    {
        return Appointment::factory()->forClient($this->client, $this->owner)->at($utcStart)->create($attributes + [
            'status' => AppointmentStatus::Requested,
            'booking_source' => BookingSource::Portal,
            'created_by' => null,
            'client_note' => 'First time; could we look at my career?',
            'reminder_minutes' => 60,
        ]);
    }

    /**
     * @return list<string>
     */
    private function timeline(): array
    {
        return ActivityEvent::withoutGlobalScopes()
            ->where('client_id', $this->client->id)
            ->where('event_type', 'like', 'appointment_%')
            ->orderBy('id')
            ->pluck('event_type')
            ->map(fn ($type) => $type->value)
            ->all();
    }

    public function test_a_request_is_confirmed_once(): void
    {
        Event::fake([AppointmentConfirmed::class]);
        $booking = $this->booking();
        $this->assertNull($booking->remind_at);

        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$booking->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.booking_source', 'portal')
            ->assertJsonPath('data.client_note', 'First time; could we look at my career?')
            ->assertJsonPath('data.remind_at', '2026-10-20T08:00:00Z')
            ->assertJsonPath('data.cancelled_by', null)
            ->assertJsonPath('data.declined_at', null)
            ->assertJsonPath('data.client.full_name', $this->client->fullName());

        $this->assertSame(['appointment_confirmed'], $this->timeline());
        $entry = ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment_confirmed')->sole();
        $this->assertSame(['type' => 'appointment', 'id' => $booking->id], ['type' => $entry->subject_type, 'id' => $entry->subject_id]);
        $this->assertSame($this->owner->id, $entry->created_by);
        Event::assertDispatched(AppointmentConfirmed::class, fn (AppointmentConfirmed $event) => $event->appointment->is($booking));

        // Only a waiting request is confirmed.
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$booking->id}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_requested')
            ->assertJsonPath('message', __('booking.not_requested'));
        Event::assertDispatchedTimes(AppointmentConfirmed::class, 1);
    }

    public function test_a_request_is_declined_with_a_reason_for_the_client(): void
    {
        Event::fake([AppointmentDeclined::class]);
        $booking = $this->booking();

        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$booking->id}/decline", ['reason' => str_repeat('x', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$booking->id}/decline", [
            'reason' => 'I am away that week; please pick a date in November.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'practice')
            ->assertJsonPath('data.declined_at', '2026-10-15T10:00:00Z')
            ->assertJsonPath('data.cancelled_at', '2026-10-15T10:00:00Z')
            ->assertJsonPath('data.cancellation_reason', 'I am away that week; please pick a date in November.');

        $this->assertSame(['appointment_cancelled'], $this->timeline());
        $entry = ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment_cancelled')->sole();
        $this->assertTrue($entry->metadata['declined']);
        $this->assertSame('I am away that week; please pick a date in November.', $entry->metadata['reason']);
        Event::assertDispatched(AppointmentDeclined::class);

        // Declined once is declined: neither again nor confirmed afterwards.
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$booking->id}/decline")
            ->assertStatus(409)->assertJsonPath('code', 'not_requested');
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$booking->id}/confirm")
            ->assertStatus(409)->assertJsonPath('code', 'not_requested');

        // A reason is not needed, and a scheduled appointment is not declined.
        $quiet = $this->booking([], '2026-10-21 09:00');
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$quiet->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.cancellation_reason', null)
            ->assertJsonPath('data.cancelled_by', 'practice');
        $this->assertArrayNotHasKey('reason', ActivityEvent::withoutGlobalScopes()
            ->where('event_type', 'appointment_cancelled')->where('subject_id', $quiet->id)->sole()->metadata);

        $scheduled = $this->booking(['status' => AppointmentStatus::Scheduled], '2026-10-22 09:00');
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$scheduled->id}/decline", ['reason' => 'No'])
            ->assertStatus(409)->assertJsonPath('code', 'not_requested');
        $this->assertSame(AppointmentStatus::Scheduled, $scheduled->fresh()->status);
    }

    public function test_the_astrologer_never_sets_or_skips_a_request(): void
    {
        $scheduled = $this->booking(['status' => AppointmentStatus::Scheduled]);
        $request = $this->booking([], '2026-10-21 09:00');

        $this->actingAs($this->owner)->patchJson("/api/v1/appointments/{$scheduled->id}", ['status' => 'requested'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        // A request is answered with confirm or decline, not given a status or cancelled.
        $this->actingAs($this->owner)->patchJson("/api/v1/appointments/{$request->id}", ['status' => 'scheduled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => __('appointments.request_status')]);
        $this->actingAs($this->owner)->patchJson("/api/v1/appointments/{$request->id}", ['status' => 'completed'])
            ->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$request->id}/cancel", ['reason' => 'Ill'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => __('appointments.decline_instead')]);
        $this->assertSame(AppointmentStatus::Requested, $request->fresh()->status);

        // Its time and details may still change; it keeps waiting.
        $this->actingAs($this->owner)->patchJson("/api/v1/appointments/{$request->id}", [
            'starts_at' => '2026-10-21T15:00', 'timezone' => 'Europe/Belgrade', 'notes' => 'Prepare the progressions',
        ])->assertOk()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.starts_at', '2026-10-21T13:00:00Z')
            ->assertJsonPath('data.notes', 'Prepare the progressions');

        // And it holds its time against the astrologer's own bookings.
        $this->actingAs($this->owner)->postJson('/api/v1/appointments', [
            'client_id' => $this->client->id, 'starts_at' => '2026-10-21T15:30', 'duration_minutes' => 30,
        ])->assertStatus(409)->assertJsonPath('conflicts.0.id', $request->id);
    }

    public function test_the_client_hears_about_a_move_or_a_cancellation_unless_the_astrologer_says_no(): void
    {
        Event::fake([AppointmentRescheduled::class, AppointmentCancelled::class]);
        $appointment = $this->booking(['status' => AppointmentStatus::Scheduled]);
        $path = "/api/v1/appointments/{$appointment->id}";

        $this->actingAs($this->owner)->patchJson($path, ['starts_at' => '2026-10-20T14:00', 'notify_client' => false])->assertOk();
        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $event) => ! $event->notifyClient
            && $event->by === AppointmentActor::Practice
            && $event->previousStart->toIso8601ZuluString() === '2026-10-20T09:00:00Z'
            && $event->appointment->starts_at->toIso8601ZuluString() === '2026-10-20T12:00:00Z');

        $this->actingAs($this->owner)->patchJson($path, ['starts_at' => '2026-10-20T15:00'])->assertOk();
        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $event) => $event->notifyClient
            && $event->appointment->starts_at->toIso8601ZuluString() === '2026-10-20T13:00:00Z');

        // Not a move, no news.
        $this->actingAs($this->owner)->patchJson($path, ['notes' => 'Zoom link sent', 'notify_client' => true])->assertOk();
        Event::assertDispatchedTimes(AppointmentRescheduled::class, 2);

        $this->actingAs($this->owner)->postJson("{$path}/cancel", ['reason' => 'Ill', 'notify_client' => 'perhaps'])
            ->assertUnprocessable()->assertJsonValidationErrors('notify_client');
        $this->actingAs($this->owner)->patchJson($path, ['notify_client' => 'perhaps'])
            ->assertUnprocessable()->assertJsonValidationErrors('notify_client');

        $this->actingAs($this->owner)->postJson("{$path}/cancel", ['reason' => 'Ill', 'notify_client' => false])
            ->assertOk()
            ->assertJsonPath('data.cancelled_by', 'practice')
            ->assertJsonPath('data.declined_at', null);
        Event::assertDispatched(AppointmentCancelled::class, fn (AppointmentCancelled $event) => ! $event->notifyClient
            && $event->by === AppointmentActor::Practice);

        $other = $this->booking(['status' => AppointmentStatus::Scheduled], '2026-10-23 09:00');
        $this->actingAs($this->owner)->postJson("/api/v1/appointments/{$other->id}/cancel", ['reason' => 'Ill'])->assertOk();
        Event::assertDispatched(AppointmentCancelled::class, fn (AppointmentCancelled $event) => $event->notifyClient
            && $event->appointment->is($other));
    }

    public function test_the_calendar_filters_requests(): void
    {
        $request = $this->booking();
        $this->booking(['status' => AppointmentStatus::Scheduled], '2026-10-20 12:00');
        $this->booking(['status' => AppointmentStatus::Cancelled, 'cancellation_reason' => 'No'], '2026-10-20 14:00');

        $list = $this->actingAs($this->owner)->getJson('/api/v1/appointments?from=2026-10-19&to=2026-10-25&status=requested')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.status', 'requested')
            ->assertJsonPath('data.0.booking_source', 'portal')
            ->json('data.0');

        // The client's message, like the notes, comes with one appointment only.
        $this->assertArrayNotHasKey('client_note', $list);
        $this->assertArrayNotHasKey('client_portal', $list);
        $this->assertArrayHasKey('cancelled_by', $list);

        $this->assertCount(3, $this->actingAs($this->owner)->getJson('/api/v1/appointments?from=2026-10-19&to=2026-10-25')->json('data'));
    }

    public function test_the_appointment_says_whether_the_client_can_use_the_portal(): void
    {
        $booking = $this->booking();
        $portal = fn () => $this->actingAs($this->owner)->getJson("/api/v1/appointments/{$booking->id}")
            ->assertOk()
            ->json('data.client_portal');

        $this->assertFalse($portal());

        $this->portalUserFor($this->client);
        $this->assertTrue($portal());

        // Not while the client is archived, nor once the access is revoked.
        $this->client->forceFill(['status' => ClientStatus::Archived])->save();
        $this->assertFalse($portal());
        $this->client->forceFill(['status' => ClientStatus::Active])->save();
        $this->assertTrue($portal());

        PortalAccess::withoutGlobalScopes()->update(['status' => PortalAccessStatus::Revoked->value]);
        $this->assertFalse($portal());

        // Another client's access says nothing about this one.
        $this->portalUserFor(Client::factory()->inWorkspace($this->owner->current_workspace_id)->create());
        $this->assertFalse($portal());
    }

    public function test_members_answer_requests_and_another_practice_cannot(): void
    {
        $member = $this->memberOf($this->owner);
        $stranger = User::factory()->withWorkspace()->create();
        $first = $this->booking();
        $second = $this->booking([], '2026-10-21 09:00');

        $this->actingAs($stranger)->postJson("/api/v1/appointments/{$first->id}/confirm")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/v1/appointments/{$second->id}/decline")->assertNotFound();
        $this->assertSame(AppointmentStatus::Requested, $first->fresh()->status);

        $this->actingAs($member)->postJson("/api/v1/appointments/{$first->id}/confirm")->assertOk();
        $this->actingAs($member)->postJson("/api/v1/appointments/{$second->id}/decline")->assertOk();
    }
}
