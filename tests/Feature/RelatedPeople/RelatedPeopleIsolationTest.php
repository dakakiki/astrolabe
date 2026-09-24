<?php

namespace Tests\Feature\RelatedPeople;

use App\Enums\RelationshipType;
use App\Models\Client;
use App\Models\ClientRelationship;
use App\Models\RelatedPerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Related people and links stay inside their workspace (docs/spec/06,
 * "Multi-tenant izolacija"), not even reachable by changing an id.
 */
class RelatedPeopleIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private Client $alicesClient;

    private Client $bobsClient;

    private RelatedPerson $bobsPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->withWorkspace()->create();
        $bob = User::factory()->withWorkspace()->create();

        $this->alicesClient = Client::factory()->inWorkspace($this->alice->current_workspace_id)->create();
        $this->bobsClient = Client::factory()->inWorkspace($bob->current_workspace_id)->create();
        $this->bobsPerson = RelatedPerson::factory()->relatedTo($this->bobsClient)->create(['first_name' => 'Secret']);
    }

    public function test_another_workspaces_person_cannot_be_read_changed_or_converted(): void
    {
        $id = $this->bobsPerson->id;

        $this->actingAs($this->alice)->getJson("/api/v1/related-people/{$id}")->assertNotFound();
        $this->actingAs($this->alice)->getJson("/api/v1/related-people/{$id}/chart")->assertNotFound();
        $this->actingAs($this->alice)->patchJson("/api/v1/related-people/{$id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->alice)->postJson("/api/v1/related-people/{$id}/convert")->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/related-people/{$id}")->assertNotFound();

        $this->assertDatabaseHas('related_people', ['id' => $id, 'first_name' => 'Secret', 'deleted_at' => null]);
    }

    public function test_another_workspaces_links_cannot_be_read_changed_or_removed(): void
    {
        $link = ClientRelationship::withoutGlobalScopes()->where('related_person_id', $this->bobsPerson->id)->sole();

        $this->actingAs($this->alice)->getJson("/api/v1/clients/{$this->bobsClient->id}/relationships")->assertNotFound();
        $this->actingAs($this->alice)->patchJson("/api/v1/client-relationships/{$link->id}", ['notes' => 'x'])->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/client-relationships/{$link->id}")->assertNotFound();

        $this->assertDatabaseHas('client_relationships', ['id' => $link->id, 'notes' => null]);
    }

    public function test_another_workspaces_client_or_person_cannot_be_linked(): void
    {
        $this->actingAs($this->alice)->postJson("/api/v1/clients/{$this->alicesClient->id}/relationships", [
            'related_client_id' => $this->bobsClient->id,
            'relationship_type' => 'partner',
        ])->assertUnprocessable()->assertJsonValidationErrors('related_client_id');

        $this->actingAs($this->alice)->postJson("/api/v1/clients/{$this->alicesClient->id}/relationships", [
            'related_person_id' => $this->bobsPerson->id,
            'relationship_type' => 'partner',
        ])->assertUnprocessable()->assertJsonValidationErrors('related_person_id');

        $this->actingAs($this->alice)->postJson('/api/v1/related-people', [
            'client_id' => $this->bobsClient->id,
            'relationship_type' => 'partner',
            'first_name' => 'Planted',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->assertSame(1, ClientRelationship::withoutGlobalScopes()->count());
    }

    public function test_a_workspace_id_sent_with_a_new_person_is_ignored(): void
    {
        $id = $this->actingAs($this->alice)->postJson('/api/v1/related-people', [
            'client_id' => $this->alicesClient->id,
            'relationship_type' => RelationshipType::Friend->value,
            'first_name' => 'Nina',
            'workspace_id' => $this->bobsClient->workspace_id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('related_people', ['id' => $id, 'workspace_id' => $this->alice->current_workspace_id]);
        $this->assertDatabaseHas('client_relationships', ['related_person_id' => $id, 'workspace_id' => $this->alice->current_workspace_id]);
    }
}
