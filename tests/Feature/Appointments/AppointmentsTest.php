<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * The calendar through the API (docs/spec/10).
 */
class AppointmentsTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->client = Client::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'first_name' => 'Ana',
            'last_name' => 'Marković',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function book(array $attributes = [], array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/appointments', $attributes + [
            'client_id' => $this->client->id,
            'starts_at' => '2026-10-05T15:00',
            'duration_minutes' => 60,
        ], $headers);
    }

    /**
     * @return list<string>
     */
    private function timelineTypes(): array
    {
        return ActivityEvent::withoutGlobalScopes()
            ->where('client_id', $this->client->id)
            ->where('event_type', 'like', 'appointment%')
            ->orderBy('id')
            ->pluck('event_type')
            ->map(fn ($type) => $type->value)
            ->all();
    }

    public function test_an_appointment_is_booked_in_local_time_and_stored_in_utc(): void
    {
        $service = Service::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'name' => 'Natal reading',
            'duration_minutes' => 90,
            'location_type' => 'in_person',
        ]);

        $response = $this->book(['service_id' => $service->id, 'duration_minutes' => null, 'notes' => 'Bring the chart'])
            ->assertCreated();

        $response->assertJsonPath('data.starts_at', '2026-10-05T13:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-10-05T14:30:00Z')
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.starts_at_local', '2026-10-05T15:00')
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.location_type', 'in_person')
            ->assertJsonPath('data.booking_source', 'manual')
            ->assertJsonPath('data.notes', 'Bring the chart')
            ->assertJsonPath('data.service.name', 'Natal reading')
            ->assertJsonPath('data.client.full_name', 'Ana Marković')
            ->assertJsonPath('data.assigned_user.id', $this->user->id)
            ->assertJsonPath('data.consultation', null);

        $event = ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment')->sole();
        $this->assertSame('2026-10-05 13:00:00', $event->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Natal reading', $event->summary);
        $this->assertSame('scheduled', $event->metadata['status']);
    }

    public function test_the_time_follows_the_zone_across_the_clock_change(): void
    {
        // Belgrade leaves summer time on 25 October 2026.
        $this->book(['starts_at' => '2026-10-24T10:00'])->assertJsonPath('data.starts_at', '2026-10-24T08:00:00Z');

        $id = $this->book(['starts_at' => '2026-10-26T10:00'])
            ->assertJsonPath('data.starts_at', '2026-10-26T09:00:00Z')
            ->json('data.id');

        // Entered for a client in New York, it is a different moment, and says so.
        $this->book(['starts_at' => '2026-10-27T10:00', 'timezone' => 'America/New_York'])
            ->assertJsonPath('data.starts_at', '2026-10-27T14:00:00Z')
            ->assertJsonPath('data.starts_at_local', '2026-10-27T10:00');

        // A new zone alone keeps the wall-clock time.
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['timezone' => 'Europe/London'])
            ->assertOk()
            ->assertJsonPath('data.starts_at_local', '2026-10-26T10:00')
            ->assertJsonPath('data.starts_at', '2026-10-26T10:00:00Z')
            ->assertJsonPath('data.duration_minutes', 60);
    }

    public function test_invalid_appointments_are_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/appointments', [
            'client_id' => $this->client->id,
            'starts_at' => '5 October',
            'status' => 'completed',
            'location_type' => 'either',
            'timezone' => 'Mars/Olympus',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'starts_at', 'duration_minutes', 'status', 'location_type', 'timezone',
        ]);

        $inactive = Service::factory()->inWorkspace($this->user->current_workspace_id)->inactive()->create();
        $this->book(['service_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    }

    public function test_an_overlap_is_refused_until_it_is_confirmed(): void
    {
        $first = $this->book(['starts_at' => '2026-10-05T15:00', 'duration_minutes' => 60])->json('data.id');

        $response = $this->book(['starts_at' => '2026-10-05T15:30', 'duration_minutes' => 60])
            ->assertStatus(409)
            ->assertJsonPath('message', __('appointments.overlap'))
            ->assertJsonPath('conflicts.0.id', $first)
            ->assertJsonPath('conflicts.0.client.full_name', 'Ana Marković');
        $this->assertArrayNotHasKey('notes', $response->json('conflicts.0'));
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());

        $this->book(['starts_at' => '2026-10-05T15:30', 'duration_minutes' => 60, 'allow_overlap' => true])->assertCreated();

        // Back to back is not an overlap.
        $this->book(['starts_at' => '2026-10-05T16:30', 'duration_minutes' => 30])->assertCreated();
        $this->book(['starts_at' => '2026-10-05T14:00', 'duration_minutes' => 60])->assertCreated();
    }

    public function test_only_the_same_astrologers_uncancelled_appointments_count_as_overlaps(): void
    {
        $colleague = $this->memberOf($this->user);
        $cancelled = $this->book(['starts_at' => '2026-10-05T15:00'])->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$cancelled}/cancel", ['reason' => 'Client ill'])->assertOk();

        // The cancelled one leaves the time free.
        $this->book(['starts_at' => '2026-10-05T15:00'])->assertCreated();

        // A colleague's calendar is their own.
        $this->book(['starts_at' => '2026-10-05T15:00', 'assigned_user_id' => $colleague->id])
            ->assertCreated()
            ->assertJsonPath('data.assigned_user.id', $colleague->id);

        // Bringing the cancelled one back needs the overlap confirmed.
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$cancelled}", ['status' => 'scheduled'])
            ->assertStatus(409);
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$cancelled}", ['status' => 'scheduled', 'allow_overlap' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.cancellation_reason', null);
    }

    public function test_moving_keeps_the_appointment_and_records_where_it_was(): void
    {
        $id = $this->book(['starts_at' => '2026-10-05T15:00'])->json('data.id');
        $this->book(['starts_at' => '2026-10-06T09:00']);

        // Moving onto another appointment is refused like booking there.
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['starts_at' => '2026-10-06T09:30'])
            ->assertStatus(409);

        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", [
            'starts_at' => '2026-10-07T11:00',
            'duration_minutes' => 45,
        ])->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.starts_at', '2026-10-07T09:00:00Z')
            ->assertJsonPath('data.duration_minutes', 45);

        $moved = ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment_rescheduled')->sole();
        $this->assertSame(['type' => 'appointment', 'id' => $id], ['type' => $moved->subject_type, 'id' => $moved->subject_id]);
        $this->assertSame('2026-10-05T13:00:00Z', $moved->metadata['from']);
        $this->assertSame('2026-10-07T09:00:00Z', $moved->metadata['to']);

        // Only a change of time is a move.
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['notes' => 'Zoom link sent'])->assertOk();
        $this->assertSame(1, ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment_rescheduled')->count());

        // The appointment's own entry moves with it.
        $this->assertSame(
            '2026-10-07 09:00:00',
            ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment')->where('subject_id', $id)->sole()
                ->occurred_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_cancelling_needs_a_reason_and_keeps_the_record(): void
    {
        $id = $this->book()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$id}/cancel", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'Client travelling'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Client travelling');

        $this->assertNotNull(Appointment::withoutGlobalScopes()->find($id)?->cancelled_at);
        $this->assertSame(['appointment', 'appointment_cancelled'], $this->timelineTypes());
        $this->assertSame(
            'Client travelling',
            ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment_cancelled')->sole()->metadata['reason'],
        );

        // Once cancelled, or once held, it cannot be cancelled (again).
        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $held = $this->book(['starts_at' => '2026-10-08T10:00'])->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$held}", ['status' => 'completed'])->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/appointments/{$held}/cancel", ['reason' => 'Too late'])
            ->assertUnprocessable();

        // There is no way to delete an appointment.
        $this->actingAs($this->user)->deleteJson("/api/v1/appointments/{$id}")->assertMethodNotAllowed();
    }

    public function test_the_calendar_lists_a_period_in_the_astrologers_zone(): void
    {
        $service = Service::factory()->inWorkspace($this->user->current_workspace_id)->create();
        $early = $this->book(['starts_at' => '2026-10-05T00:30', 'notes' => 'Private'])->json('data.id');
        $late = $this->book(['starts_at' => '2026-10-05T23:30', 'service_id' => $service->id])->json('data.id');
        $this->book(['starts_at' => '2026-10-06T10:00', 'location_type' => 'in_person']);
        $this->book(['starts_at' => '2026-10-04T23:00', 'duration_minutes' => 15]);

        $ids = fn (string $query) => collect($this->actingAs($this->user)->getJson('/api/v1/appointments?'.$query)
            ->assertOk()->json('data'))->pluck('id')->all();

        // 00:30 on the 5th in Belgrade is still the 4th in UTC, and it belongs to the 5th.
        $this->assertSame([$early, $late], $ids('from=2026-10-05&to=2026-10-05'));
        $this->assertSame([$late], $ids("from=2026-10-05&to=2026-10-06&service_id={$service->id}"));
        $this->assertCount(1, $ids('from=2026-10-01&to=2026-10-31&location_type=in_person'));

        $list = $this->actingAs($this->user)->getJson('/api/v1/appointments?from=2026-10-05&to=2026-10-05')->json('data.0');
        $this->assertArrayNotHasKey('notes', $list);
        $this->actingAs($this->user)->getJson("/api/v1/appointments/{$early}")->assertJsonPath('data.notes', 'Private');

        $this->actingAs($this->user)->getJson('/api/v1/appointments?from=2026-10-01&to=2027-01-31')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($this->user)->getJson('/api/v1/appointments')
            ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_a_repeated_request_with_the_same_key_books_once(): void
    {
        $headers = ['Idempotency-Key' => 'booking-7f3a9c'];

        $first = $this->book([], $headers)->assertCreated()->json('data.id');
        $this->book([], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());

        // The same key for a different request is a mistake, not a replay.
        $this->book(['starts_at' => '2026-11-01T10:00'], $headers)->assertUnprocessable();

        // A refused overlap is not remembered: confirming it with the same key works.
        $other = ['Idempotency-Key' => 'booking-8d2e1b'];
        $this->book(['starts_at' => '2026-10-05T15:15'], $other)->assertStatus(409);
        $this->book(['starts_at' => '2026-10-05T15:15', 'allow_overlap' => true], $other)->assertCreated();

        $this->book([], ['Idempotency-Key' => 'short'])->assertUnprocessable();
    }

    public function test_a_consultation_is_recorded_from_an_appointment_once(): void
    {
        $service = Service::factory()->inWorkspace($this->user->current_workspace_id)->create(['name' => 'Natal reading']);
        $id = $this->book(['service_id' => $service->id])->json('data.id');

        $consultation = $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $this->client->id,
            'appointment_id' => $id,
            'service_id' => $service->id,
            'status' => 'completed',
            'starts_at' => '2026-10-05T15:00',
        ])->assertCreated()
            ->assertJsonPath('data.appointment.id', $id)
            ->assertJsonPath('data.appointment.status', 'completed')
            ->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/appointments/{$id}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.consultation.id', $consultation);

        // The consultation now stands for the session on the timeline.
        $this->assertSame([], $this->timelineTypes());

        $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $this->client->id,
            'appointment_id' => $id,
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors(['appointment_id' => __('appointments.already_recorded')]);

        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$consultation}", ['appointment_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');

        // Deleting the consultation brings the appointment back to the timeline,
        // and a new consultation may be recorded from it.
        $this->actingAs($this->user)->deleteJson("/api/v1/consultations/{$consultation}")->assertNoContent();
        $this->assertSame(['appointment'], $this->timelineTypes());
    }

    public function test_an_appointment_of_another_client_cannot_be_recorded(): void
    {
        $id = $this->book()->json('data.id');
        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();

        $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $other->id,
            'appointment_id' => $id,
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors(['appointment_id' => __('appointments.other_client')]);
    }

    public function test_a_service_with_appointments_is_not_deleted(): void
    {
        $service = Service::factory()->inWorkspace($this->user->current_workspace_id)->create();
        $this->book(['service_id' => $service->id]);

        $this->actingAs($this->user)->getJson("/api/v1/services/{$service->id}")->assertJsonPath('data.in_use', true);
        $this->actingAs($this->user)->deleteJson("/api/v1/services/{$service->id}")->assertStatus(409);
    }

    public function test_the_timeline_filters_appointments_and_the_rebuild_restores_them(): void
    {
        $id = $this->book()->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/appointments/{$id}", ['starts_at' => '2026-10-05T16:00'])->assertOk();
        Consultation::factory()->forClient($this->client)->create();

        $types = fn () => collect($this->actingAs($this->user)
            ->getJson("/api/v1/clients/{$this->client->id}/timeline?type=appointments")->assertOk()->json('data'))
            ->pluck('type')->all();

        $this->assertEqualsCanonicalizing(['appointment', 'appointment_rescheduled'], $types());

        ActivityEvent::withoutGlobalScopes()->where('event_type', 'appointment')->delete();
        $this->artisan('activity:rebuild')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['appointment', 'appointment_rescheduled'], $types());
    }

    public function test_a_member_books_for_themselves_by_default(): void
    {
        $member = $this->memberOf($this->user);

        $this->actingAs($member)->postJson('/api/v1/appointments', [
            'client_id' => $this->client->id,
            'starts_at' => '2026-10-05T15:00',
            'duration_minutes' => 30,
        ])->assertCreated()->assertJsonPath('data.assigned_user.id', $member->id);

        $this->assertSame(AppointmentStatus::Scheduled, Appointment::withoutGlobalScopes()->sole()->status);
    }
}
