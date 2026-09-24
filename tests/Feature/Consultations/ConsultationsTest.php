<?php

namespace Tests\Feature\Consultations;

use App\Models\AstrologyMethod;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Consultations through the API (docs/spec/02, "Konsultacije").
 */
class ConsultationsTest extends TestCase
{
    use RefreshDatabase;

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
    private function create(array $attributes = []): int
    {
        return $this->actingAs($this->user)->postJson('/api/v1/consultations', $attributes + [
            'client_id' => $this->client->id,
            'title' => 'Natal reading',
            'status' => 'completed',
            'starts_at' => '2026-07-15T14:30',
        ])->assertCreated()->json('data.id');
    }

    public function test_a_consultation_is_recorded_with_its_separate_notes_and_methods(): void
    {
        $western = AstrologyMethod::withoutGlobalScopes()->where('slug', 'western')->value('id');

        $response = $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $this->client->id,
            'title' => 'Natal reading',
            'status' => 'completed',
            'starts_at' => '2026-07-15T14:30',
            'duration_minutes' => 75,
            'topics' => 'Career change; relocation',
            'internal_notes' => '<p>Saturn return is the real question.</p>',
            'client_summary' => '<p>A year for <strong>structure</strong>.</p>',
            'next_steps' => '<ul><li>Solar return in March</li></ul>',
            'method_ids' => [$western],
        ])->assertCreated();

        $response
            ->assertJsonPath('data.client.full_name', 'Ana Marković')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.duration_minutes', 75)
            ->assertJsonPath('data.methods.0.slug', 'western')
            ->assertJsonPath('data.internal_notes', '<p>Saturn return is the real question.</p>')
            ->assertJsonPath('data.client_summary', '<p>A year for <strong>structure</strong>.</p>')
            ->assertJsonPath('data.next_steps', '<ul><li>Solar return in March</li></ul>')
            ->assertJsonPath('data.has_chart', false);

        $this->assertDatabaseHas('consultations', [
            'id' => $response->json('data.id'),
            'workspace_id' => $this->user->current_workspace_id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_the_start_is_entered_in_a_time_zone_and_stored_in_utc(): void
    {
        // Summer time in Belgrade: UTC+2. The zone defaults to the astrologer's own.
        $id = $this->create(['starts_at' => '2026-07-15T14:30']);

        $this->actingAs($this->user)->getJson("/api/v1/consultations/{$id}")
            ->assertJsonPath('data.starts_at', '2026-07-15T12:30:00Z')
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.starts_at_local', '2026-07-15T14:30');

        // A client abroad: the same wall-clock time in New York is 18:30 UTC.
        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$id}", ['timezone' => 'America/New_York'])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-07-15T18:30:00Z')
            ->assertJsonPath('data.starts_at_local', '2026-07-15T14:30');
    }

    public function test_only_a_draft_may_be_without_a_date(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/consultations', ['client_id' => $this->client->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.starts_at', null);

        $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $this->client->id,
            'status' => 'scheduled',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $draft = Consultation::query()->withoutGlobalScopes()->where('status', 'draft')->value('id');

        // Changing only the status of a dateless draft is checked against the stored date.
        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$draft}", ['status' => 'completed'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$draft}", [
            'status' => 'completed',
            'starts_at' => '2026-09-01T10:00',
        ])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_formatted_text_is_sanitized_and_plain_text_keeps_its_paragraphs(): void
    {
        $id = $this->create([
            'internal_notes' => '<p onclick="steal()">Hi <script>alert(1)</script><a href="javascript:alert(1)">x</a>'
                .'<img src="x" onerror="y()"><a href="https://example.com/chart">chart</a></p><style>p{}</style>',
            'client_summary' => "First paragraph.\nSecond line.\n\nNew paragraph <3",
        ]);

        $consultation = Consultation::withoutGlobalScopes()->find($id);

        foreach (['script', 'onclick', 'javascript:', '<img', 'onerror', 'style'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $consultation->internal_notes);
        }
        $this->assertStringContainsString('<a href="https://example.com/chart" rel="noopener noreferrer nofollow" target="_blank">chart</a>', $consultation->internal_notes);
        $this->assertSame('<p>First paragraph.<br />Second line.</p><p>New paragraph &lt;3</p>', $consultation->client_summary);
    }

    public function test_an_emptied_editor_stores_nothing(): void
    {
        $id = $this->create(['client_summary' => '<p></p>']);

        $this->assertNull(Consultation::withoutGlobalScopes()->find($id)->client_summary);
    }

    public function test_lists_leave_out_the_notes_and_filter_by_client_status_and_dates(): void
    {
        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create(['first_name' => 'Ivo']);

        $this->create(['starts_at' => '2026-07-15T14:30', 'internal_notes' => '<p>Private</p>']);
        $this->create(['starts_at' => '2026-08-01T09:00', 'status' => 'scheduled']);
        $this->create(['client_id' => $other->id, 'starts_at' => '2026-08-20T09:00']);

        $all = $this->actingAs($this->user)->getJson('/api/v1/consultations')->assertOk();
        $all->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.client.full_name', $other->fullName())
            ->assertJsonMissingPath('data.0.internal_notes')
            ->assertJsonMissingPath('data.0.client_summary');

        $this->actingAs($this->user)->getJson("/api/v1/consultations?client_id={$this->client->id}")
            ->assertJsonCount(2, 'data');
        $this->actingAs($this->user)->getJson('/api/v1/consultations?status=scheduled')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_at_local', '2026-08-01T09:00');
        $this->actingAs($this->user)->getJson('/api/v1/consultations?from=2026-08-01&to=2026-08-01')
            ->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/api/v1/consultations?search=ivo')
            ->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/api/v1/consultations?sort=starts_at')
            ->assertJsonPath('data.0.starts_at_local', '2026-07-15T14:30');
    }

    public function test_the_client_cannot_be_changed_after_creation(): void
    {
        $id = $this->create();
        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();

        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$id}", ['client_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_recording_a_consultation_marks_the_client_as_active(): void
    {
        $this->client->forceFill(['last_activity_at' => now()->subYear()])->saveQuietly();

        $this->create();

        $this->assertTrue($this->client->fresh()->last_activity_at->isToday());
    }

    public function test_deleting_a_consultation_takes_its_files_but_leaves_the_notes(): void
    {
        Storage::fake('attachments');
        $id = $this->create();

        $this->actingAs($this->user)->post('/api/v1/attachments', [
            'consultation_id' => $id,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Reading notes'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/notes', [
            'client_id' => $this->client->id,
            'consultation_id' => $id,
            'content' => 'Follow up in spring.',
        ])->assertCreated();

        $this->actingAs($this->user)->deleteJson("/api/v1/consultations/{$id}")->assertNoContent();

        $this->actingAs($this->user)->getJson("/api/v1/consultations/{$id}")->assertNotFound();
        $this->assertSoftDeleted('consultations', ['id' => $id]);
        $this->assertSoftDeleted('attachments', ['attachable_id' => $id]);
        $this->assertSame(1, Note::withoutGlobalScopes()->whereNull('deleted_at')->count());
        $this->actingAs($this->user)->getJson("/api/v1/notes?client_id={$this->client->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.consultation', null);
    }

    public function test_the_profile_counts_consultations_notes_and_files(): void
    {
        $this->create();
        $this->create(['status' => 'scheduled', 'starts_at' => '2026-12-01T10:00']);
        $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $this->client->id, 'content' => 'A note'])
            ->assertCreated();

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$this->client->id}")
            ->assertJsonPath('data.stats.consultations', 2)
            ->assertJsonPath('data.stats.completed_consultations', 1)
            ->assertJsonPath('data.stats.notes', 1)
            ->assertJsonPath('data.stats.files', 0);
    }

    public function test_reference_data_lists_consultation_statuses_visibilities_and_file_limits(): void
    {
        config(['astrolabe.attachments.max_size_mb' => 1]);

        $this->actingAs($this->user)->getJson('/api/v1/reference-data')
            ->assertJsonPath('data.consultation_statuses', ['draft', 'scheduled', 'completed', 'cancelled', 'no_show'])
            ->assertJsonPath('data.visibilities', ['private', 'team', 'shared_with_client'])
            ->assertJsonPath('data.attachments.max_size', 1024 * 1024)
            ->assertJsonFragment(['extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'docx', 'txt', 'md', 'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'mov', 'webm']]);
    }
}
