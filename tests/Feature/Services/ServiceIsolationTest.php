<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Services stay inside their workspace (docs/spec/06, "Multi-tenant izolacija").
 */
class ServiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private Service $bobsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->withWorkspace()->create();
        $bob = User::factory()->withWorkspace()->create();
        $this->bobsService = Service::factory()->inWorkspace($bob->current_workspace_id)->create(['name' => 'Bob only']);
    }

    public function test_another_workspaces_service_is_not_listed_or_found(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/services')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/services/{$this->bobsService->id}")->assertNotFound();
    }

    public function test_another_workspaces_service_cannot_be_changed_or_deleted(): void
    {
        $id = $this->bobsService->id;

        $this->actingAs($this->alice)->patchJson("/api/v1/services/{$id}", ['name' => 'Stolen'])->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/services/{$id}")->assertNotFound();

        $this->assertDatabaseHas('services', ['id' => $id, 'name' => 'Bob only']);
    }

    public function test_another_workspaces_service_cannot_be_put_on_a_consultation(): void
    {
        $client = Client::factory()->inWorkspace($this->alice->current_workspace_id)->create();

        $this->actingAs($this->alice)->postJson('/api/v1/consultations', [
            'client_id' => $client->id,
            'service_id' => $this->bobsService->id,
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    }

    public function test_the_same_name_is_free_in_another_workspace(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/services', [
            'name' => 'Bob only',
            'duration_minutes' => 60,
            'location_type' => 'online',
        ])->assertCreated();
    }
}
