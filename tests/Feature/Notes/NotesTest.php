<?php

namespace Tests\Feature\Notes;

use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Notes and their visibility (docs/spec/02, "Beleške").
 */
class NotesTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withWorkspace()->create();
        $this->client = Client::factory()->inWorkspace($this->owner->current_workspace_id)->create();
    }

    public function test_a_note_is_private_unless_marked_otherwise_and_its_content_is_sanitized(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/notes', [
            'client_id' => $this->client->id,
            'title' => 'After the reading',
            'content' => '<h3>Mood</h3><p>Calm, <em>curious</em>.</p><iframe src="https://evil.example"></iframe>',
        ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.title', 'After the reading')
            ->assertJsonPath('data.content', '<h3>Mood</h3><p>Calm, <em>curious</em>.</p>')
            ->assertJsonPath('data.author.id', $this->owner->id)
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_a_note_needs_readable_content(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/notes', [
            'client_id' => $this->client->id,
            'content' => '<p> </p><p></p>',
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
    }

    public function test_a_note_may_point_only_at_a_consultation_of_the_same_client(): void
    {
        $own = Consultation::factory()->forClient($this->client)->create();
        $other = Consultation::factory()->forClient(
            Client::factory()->inWorkspace($this->owner->current_workspace_id)->create()
        )->create();

        $this->actingAs($this->owner)->postJson('/api/v1/notes', [
            'client_id' => $this->client->id,
            'consultation_id' => $other->id,
            'content' => 'Wrong client',
        ])->assertUnprocessable()->assertJsonValidationErrors('consultation_id');

        $this->actingAs($this->owner)->postJson('/api/v1/notes', [
            'client_id' => $this->client->id,
            'consultation_id' => $own->id,
            'content' => 'Right client',
        ])->assertCreated()->assertJsonPath('data.consultation.id', $own->id);

        $this->actingAs($this->owner)->getJson("/api/v1/notes?consultation_id={$own->id}")->assertJsonCount(1, 'data');
    }

    public function test_notes_are_listed_newest_first_and_can_be_edited_and_deleted(): void
    {
        $first = $this->actingAs($this->owner)->postJson('/api/v1/notes', ['client_id' => $this->client->id, 'content' => 'One'])
            ->json('data.id');
        $this->travel(1)->minutes();
        $second = $this->actingAs($this->owner)->postJson('/api/v1/notes', ['client_id' => $this->client->id, 'content' => 'Two'])
            ->json('data.id');

        $this->actingAs($this->owner)->getJson("/api/v1/notes?client_id={$this->client->id}")
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first);

        $this->actingAs($this->owner)->patchJson("/api/v1/notes/{$first}", ['content' => 'One, revised', 'visibility' => 'team'])
            ->assertOk()
            ->assertJsonPath('data.content', '<p>One, revised</p>')
            ->assertJsonPath('data.visibility', 'team');

        $this->actingAs($this->owner)->patchJson("/api/v1/notes/{$first}", ['client_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->actingAs($this->owner)->deleteJson("/api/v1/notes/{$first}")->assertNoContent();
        $this->assertSoftDeleted('notes', ['id' => $first]);
        $this->actingAs($this->owner)->getJson("/api/v1/notes?client_id={$this->client->id}")->assertJsonCount(1, 'data');
    }

    public function test_a_private_note_exists_only_for_its_author(): void
    {
        $member = $this->memberOf($this->owner);
        $note = Note::factory()->forClient($this->client, $this->owner)->create(['title' => 'Mine only']);

        $this->actingAs($member)->getJson("/api/v1/notes?client_id={$this->client->id}")->assertJsonCount(0, 'data');
        $this->actingAs($member)->getJson("/api/v1/notes/{$note->id}")->assertNotFound();
        $this->actingAs($member)->patchJson("/api/v1/notes/{$note->id}", ['content' => 'x'])->assertNotFound();
        $this->actingAs($member)->deleteJson("/api/v1/notes/{$note->id}")->assertNotFound();

        $this->assertDatabaseHas('notes', ['id' => $note->id, 'deleted_at' => null]);
    }

    public function test_a_team_note_is_read_by_members_and_changed_by_its_author_or_the_owner(): void
    {
        $member = $this->memberOf($this->owner);
        $ownersNote = Note::factory()->forClient($this->client, $this->owner)->create(['visibility' => 'team']);
        $membersNote = Note::factory()->forClient($this->client, $member)->create(['visibility' => 'team']);

        $this->actingAs($member)->getJson("/api/v1/notes?client_id={$this->client->id}")
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $ownersNote->id, 'can_edit' => false]);

        $this->actingAs($member)->patchJson("/api/v1/notes/{$ownersNote->id}", ['content' => 'x'])->assertForbidden();
        $this->actingAs($member)->patchJson("/api/v1/notes/{$membersNote->id}", ['content' => 'Mine'])->assertOk();
        $this->actingAs($this->owner)->patchJson("/api/v1/notes/{$membersNote->id}", ['content' => 'Owner edit'])->assertOk();
    }
}
