<?php

namespace Tests\Feature\Clients;

use App\Models\AstrologyMethod;
use App\Models\Client;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clients and everything attached to them stay inside their workspace
 * (docs/spec/06, "Multi-tenant izolacija").
 */
class ClientIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Client $bobsClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->withWorkspace()->create();
        $this->bob = User::factory()->withWorkspace()->create();

        $this->bobsClient = Client::factory()->inWorkspace($this->bob->current_workspace_id)->create([
            'first_name' => 'Secret',
            'last_name' => 'Client',
            'internal_notes' => 'Bob only',
        ]);

        app(CurrentWorkspace::class)->run(Workspace::find($this->bob->current_workspace_id), function () {
            $this->bobsClient->tags()->create(['name' => 'bob-tag']);
        });
    }

    public function test_another_workspaces_client_is_not_listed_or_found(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/clients?status=all&search=Secret')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->alice)->getJson("/api/v1/clients/{$this->bobsClient->id}")->assertNotFound();
    }

    public function test_another_workspaces_client_cannot_be_changed_by_id(): void
    {
        $id = $this->bobsClient->id;

        $this->actingAs($this->alice)->patchJson("/api/v1/clients/{$id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->alice)->putJson("/api/v1/clients/{$id}/birth-details", ['time_accuracy' => 'unknown'])
            ->assertNotFound();
        $this->actingAs($this->alice)->postJson("/api/v1/clients/{$id}/archive")->assertNotFound();

        $this->assertDatabaseHas('clients', ['id' => $id, 'first_name' => 'Secret', 'status' => 'active']);
        $this->assertDatabaseMissing('client_birth_details', ['client_id' => $id]);
    }

    public function test_tags_are_per_workspace(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/tags')->assertOk()->assertJsonCount(0, 'data');

        // The same name creates Alice's own tag, never reuses Bob's.
        $this->actingAs($this->alice)->postJson('/api/v1/clients', ['first_name' => 'Ana', 'tags' => ['bob-tag']])
            ->assertCreated();

        $this->assertSame(2, Tag::withoutGlobalScopes()->where('name', 'bob-tag')->count());
    }

    public function test_another_workspaces_method_cannot_be_attached_to_a_client(): void
    {
        $bobsMethod = AstrologyMethod::factory()->create(['workspace_id' => $this->bob->current_workspace_id]);

        $this->actingAs($this->alice)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'method_ids' => [$bobsMethod->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('method_ids.0');
    }

    public function test_a_client_cannot_be_assigned_to_someone_outside_the_workspace(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'assigned_user_id' => $this->bob->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
    }

    public function test_a_workspace_id_sent_with_a_new_client_is_ignored(): void
    {
        $id = $this->actingAs($this->alice)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'workspace_id' => $this->bob->current_workspace_id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('clients', ['id' => $id, 'workspace_id' => $this->alice->current_workspace_id]);
    }
}
