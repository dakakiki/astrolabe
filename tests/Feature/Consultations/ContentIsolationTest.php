<?php

namespace Tests\Feature\Consultations;

use App\Enums\Visibility;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Consultations, notes, files and timelines stay inside their workspace, even
 * when ids are changed by hand (docs/spec/06, "Multi-tenant izolacija").
 */
class ContentIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Client $bobsClient;

    private Consultation $bobsConsultation;

    private Note $bobsNote;

    private Attachment $bobsFile;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->alice = User::factory()->withWorkspace()->create();
        $this->bob = User::factory()->withWorkspace()->create();

        $this->bobsClient = Client::factory()->inWorkspace($this->bob->current_workspace_id)->create();
        $this->bobsConsultation = Consultation::factory()->forClient($this->bobsClient)->create(['internal_notes' => '<p>Bob only</p>']);
        $this->bobsNote = Note::factory()->forClient($this->bobsClient, $this->bob)->create(['visibility' => Visibility::Team]);

        $id = $this->actingAs($this->bob)->post('/api/v1/attachments', [
            'consultation_id' => $this->bobsConsultation->id,
            'visibility' => 'team',
            'file' => UploadedFile::fake()->createWithContent('bob.txt', 'Bob only'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->bobsFile = app(CurrentWorkspace::class)->run(
            Workspace::find($this->bob->current_workspace_id),
            fn () => Attachment::query()->findOrFail($id),
        );
    }

    public function test_another_workspaces_content_is_never_listed(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/consultations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/consultations?client_id={$this->bobsClient->id}")->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/notes?client_id={$this->bobsClient->id}")->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/attachments?client_id={$this->bobsClient->id}")->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/attachments?consultation_id={$this->bobsConsultation->id}")->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/clients/{$this->bobsClient->id}/timeline")->assertNotFound();
    }

    public function test_another_workspaces_content_cannot_be_read_changed_or_downloaded_by_id(): void
    {
        $consultation = $this->bobsConsultation->id;

        $this->actingAs($this->alice)->getJson("/api/v1/consultations/{$consultation}")->assertNotFound();
        $this->actingAs($this->alice)->patchJson("/api/v1/consultations/{$consultation}", ['title' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/consultations/{$consultation}")->assertNotFound();
        $this->actingAs($this->alice)->postJson("/api/v1/consultations/{$consultation}/chart")->assertNotFound();

        $this->actingAs($this->alice)->getJson("/api/v1/notes/{$this->bobsNote->id}")->assertNotFound();
        $this->actingAs($this->alice)->patchJson("/api/v1/notes/{$this->bobsNote->id}", ['content' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/notes/{$this->bobsNote->id}")->assertNotFound();

        $this->actingAs($this->alice)->get("/api/v1/attachments/{$this->bobsFile->id}/download")->assertNotFound();
        $this->actingAs($this->alice)->patchJson("/api/v1/attachments/{$this->bobsFile->id}", ['visibility' => 'private'])->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/attachments/{$this->bobsFile->id}")->assertNotFound();

        $this->assertDatabaseHas('consultations', ['id' => $consultation, 'title' => 'Natal reading', 'deleted_at' => null]);
        $this->assertDatabaseHas('notes', ['id' => $this->bobsNote->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('attachments', ['id' => $this->bobsFile->id, 'visibility' => 'team', 'deleted_at' => null]);
    }

    public function test_nothing_can_be_added_to_another_workspaces_client_or_consultation(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/consultations', ['client_id' => $this->bobsClient->id])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->actingAs($this->alice)->postJson('/api/v1/notes', ['client_id' => $this->bobsClient->id, 'content' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $alicesClient = Client::factory()->inWorkspace($this->alice->current_workspace_id)->create();
        $this->actingAs($this->alice)->postJson('/api/v1/notes', [
            'client_id' => $alicesClient->id,
            'consultation_id' => $this->bobsConsultation->id,
            'content' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('consultation_id');

        $this->actingAs($this->alice)->post('/api/v1/attachments', [
            'consultation_id' => $this->bobsConsultation->id,
            'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('consultation_id');

        $this->actingAs($this->alice)->post('/api/v1/attachments', [
            'client_id' => $this->bobsClient->id,
            'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());
    }

    public function test_a_workspace_id_sent_with_new_content_is_ignored(): void
    {
        $client = Client::factory()->inWorkspace($this->alice->current_workspace_id)->create();

        $id = $this->actingAs($this->alice)->postJson('/api/v1/consultations', [
            'client_id' => $client->id,
            'workspace_id' => $this->bob->current_workspace_id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('consultations', ['id' => $id, 'workspace_id' => $this->alice->current_workspace_id]);
        $this->assertDatabaseHas('activity_events', ['subject_id' => $id, 'event_type' => 'consultation', 'workspace_id' => $this->alice->current_workspace_id]);
    }
}
