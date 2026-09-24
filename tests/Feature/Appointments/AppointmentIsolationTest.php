<?php

namespace Tests\Feature\Appointments;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Appointments stay inside their workspace (docs/spec/06, "Multi-tenant
 * izolacija"), not even reachable by changing an id.
 */
class AppointmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Client $alicesClient;

    private Appointment $bobsAppointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->withWorkspace()->create();
        $this->bob = User::factory()->withWorkspace()->create();
        $this->alicesClient = Client::factory()->inWorkspace($this->alice->current_workspace_id)->create();

        $bobsClient = Client::factory()->inWorkspace($this->bob->current_workspace_id)->create();
        $this->bobsAppointment = Appointment::factory()->forClient($bobsClient, $this->bob)
            ->at('2026-10-05 10:00')
            ->create(['notes' => 'Bob only']);
    }

    public function test_another_workspaces_appointment_is_not_listed_or_found(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/appointments?from=2026-10-01&to=2026-10-31')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->alice)->getJson("/api/v1/appointments/{$this->bobsAppointment->id}")->assertNotFound();
    }

    public function test_another_workspaces_appointment_cannot_be_changed_or_cancelled(): void
    {
        $id = $this->bobsAppointment->id;

        $this->actingAs($this->alice)->patchJson("/api/v1/appointments/{$id}", ['notes' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->alice)->postJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'x'])->assertNotFound();

        $this->assertDatabaseHas('appointments', ['id' => $id, 'notes' => 'Bob only', 'status' => 'scheduled']);
    }

    public function test_bobs_time_does_not_block_alice(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/appointments', [
            'client_id' => $this->alicesClient->id,
            'starts_at' => '2026-10-05T12:00',
            'timezone' => 'Europe/Belgrade',
            'duration_minutes' => 60,
        ])->assertCreated();
    }

    public function test_another_workspaces_client_astrologer_or_appointment_cannot_be_used(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/appointments', [
            'client_id' => $this->bobsAppointment->client_id,
            'starts_at' => '2026-10-05T12:00',
            'duration_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->actingAs($this->alice)->postJson('/api/v1/appointments', [
            'client_id' => $this->alicesClient->id,
            'assigned_user_id' => $this->bob->id,
            'starts_at' => '2026-10-05T12:00',
            'duration_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');

        $this->actingAs($this->alice)->postJson('/api/v1/consultations', [
            'client_id' => $this->alicesClient->id,
            'appointment_id' => $this->bobsAppointment->id,
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
    }
}
