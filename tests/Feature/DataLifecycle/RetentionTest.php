<?php

namespace Tests\Feature\DataLifecycle;

use App\Enums\AuditEvent;
use App\Enums\ExportStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Payment;
use App\Models\RegistrationInvitation;
use App\Models\RelatedPerson;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The retention rules (`data:prune`, Phase 8b): what was deleted stays
 * restorable for 30 days and then goes for good, files from the disk too; the
 * audit log is kept 12 months; exports and leftovers go after their own periods.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('exports');
        $this->owner = User::factory()->withWorkspace()->create();
        $this->client = Client::factory()->inWorkspace($this->owner->current_workspace_id)->create();
    }

    private function prune(): void
    {
        $this->app['auth']->forgetGuards();
        $this->artisan('data:prune')->assertSuccessful();
    }

    public function test_deleted_rows_go_for_good_after_thirty_days(): void
    {
        $old = Note::factory()->forClient($this->client, $this->owner)->create();
        $recent = Note::factory()->forClient($this->client, $this->owner)->create();
        $kept = Note::factory()->forClient($this->client, $this->owner)->create();
        $task = Task::factory()->forClient($this->client, $this->owner)->create();
        $payment = Payment::factory()->fromClient($this->client)->create();

        $old->delete();
        $task->delete();
        $payment->delete();
        $this->travel(20)->days();
        $recent->delete();
        $this->travel(11)->days();

        $this->prune();

        $this->assertDatabaseMissing('notes', ['id' => $old->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertSoftDeleted($recent);
        $this->assertNotSoftDeleted($kept);
    }

    public function test_a_deleted_file_leaves_the_disk(): void
    {
        $this->actingAs($this->owner)->post('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'file' => UploadedFile::fake()->createWithContent('Old chart.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertCreated();
        $attachment = DB::table('attachments')->sole();
        $this->actingAs($this->owner)->deleteJson("/api/v1/attachments/{$attachment->id}")->assertNoContent();
        Storage::disk('attachments')->assertExists($attachment->storage_path);

        $this->travel(31)->days();
        $this->prune();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('attachments')->assertMissing($attachment->storage_path);
    }

    public function test_a_deleted_consultation_goes_and_its_payment_stays_with_the_client(): void
    {
        $consultation = Consultation::factory()->forClient($this->client)->create();
        $payment = Payment::factory()->forConsultation($consultation)->create();
        $this->actingAs($this->owner)->deleteJson("/api/v1/consultations/{$consultation->id}")->assertNoContent();

        $this->travel(31)->days();
        $this->prune();

        $this->assertDatabaseMissing('consultations', ['id' => $consultation->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'client_id' => $this->client->id, 'consultation_id' => null]);
    }

    public function test_a_removed_related_person_goes_with_their_chart(): void
    {
        $person = RelatedPerson::factory()->relatedTo($this->client)->create();
        DB::table('chart_calculations')->insert([
            'workspace_id' => $this->client->workspace_id, 'subject_type' => 'related_person', 'subject_id' => $person->id,
            'chart_type' => 'natal', 'input_hash' => str_repeat('a', 64), 'julian_day_ut' => 2451545, 'zodiac_mode' => 'tropical',
            'time_accuracy' => 'unknown', 'payload' => '{}', 'engine_name' => 'fake', 'engine_version' => '1',
            'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->owner)->deleteJson("/api/v1/related-people/{$person->id}")->assertNoContent();

        $this->travel(31)->days();
        $this->prune();

        $this->assertDatabaseMissing('related_people', ['id' => $person->id]);
        $this->assertSame(0, DB::table('chart_calculations')->count());
    }

    public function test_the_audit_log_is_kept_for_twelve_months(): void
    {
        $old = AuditLog::query()->create(['event' => AuditEvent::Login, 'created_at' => now()->subMonths(12)->subDay()]);
        $recent = AuditLog::query()->create(['event' => AuditEvent::Login, 'created_at' => now()->subMonths(11)]);

        $this->prune();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        // The run itself is recorded, with counts only.
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::RetentionApplied)->sole()->properties['audit_logs']);
    }

    public function test_exports_go_when_they_expire(): void
    {
        Storage::disk('exports')->put('1/old.zip', 'zip');
        Storage::disk('exports')->put('1/new.zip', 'zip');
        $base = ['workspace_id' => $this->owner->current_workspace_id, 'status' => ExportStatus::Ready, 'disk' => 'exports', 'completed_at' => now()];
        $old = WorkspaceExport::withoutGlobalScopes()->forceCreate($base + ['path' => '1/old.zip', 'expires_at' => now()->subMinute()]);
        $new = WorkspaceExport::withoutGlobalScopes()->forceCreate($base + ['path' => '1/new.zip', 'expires_at' => now()->addDay()]);
        $stuck = WorkspaceExport::withoutGlobalScopes()->forceCreate(['workspace_id' => $this->owner->current_workspace_id, 'status' => ExportStatus::Failed]);
        $stuck->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->prune();

        $this->assertModelMissing($old);
        $this->assertModelMissing($stuck);
        $this->assertModelExists($new);
        Storage::disk('exports')->assertMissing('1/old.zip');
        Storage::disk('exports')->assertExists('1/new.zip');
    }

    public function test_old_invitations_and_failed_jobs_are_cleared(): void
    {
        [$expired] = RegistrationInvitation::issue('late@example.com', 14);
        $expired->forceFill(['expires_at' => now()->subDays(31)])->save();
        [$fresh] = RegistrationInvitation::issue('soon@example.com', 14);
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(31)],
            ['uuid' => 'b', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDay()],
        ]);

        $this->prune();

        $this->assertModelMissing($expired);
        $this->assertModelExists($fresh);
        $this->assertSame(['b'], DB::table('failed_jobs')->pluck('uuid')->all());
    }

    public function test_a_dry_run_only_counts(): void
    {
        $note = Note::factory()->forClient($this->client, $this->owner)->create();
        $note->delete();
        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();

        $this->artisan('data:prune', ['--dry-run' => true])
            ->expectsTable(['What', 'Would remove'], [
                ['practices', 0], ['files', 0], ['notes', 1], ['tasks', 0], ['payments', 0], ['consultations', 0],
                ['appointments', 0], ['related_people', 0], ['audit_logs', 0], ['exports', 0], ['invitations', 0],
                ['failed_jobs', 0], ['password_resets', 0],
                // The client portal (Phase 9a).
                ['portal_sign_in_tokens', 0], ['portal_invitations', 0], ['portal_sessions', 0], ['portal_accounts', 0],
            ])
            ->assertSuccessful();

        $this->assertSoftDeleted($note);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::RetentionApplied)->count());
    }
}
