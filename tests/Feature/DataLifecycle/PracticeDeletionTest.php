<?php

namespace Tests\Feature\DataLifecycle;

use App\Enums\AuditEvent;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AppointmentReminder;
use App\Notifications\PracticeDeleted;
use App\Notifications\PracticeDeletionCancelled;
use App\Notifications\PracticeDeletionScheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Deleting the practice and the accounts that belong only to it (Phase 8b): the
 * owner schedules it with their password, the practice is closed for 30 days
 * and can be brought back, then `data:prune` deletes everything for good.
 */
class PracticeDeletionTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('exports');
        Notification::fake();
        $this->owner = User::factory()->withWorkspace()->create();
        $this->workspace = $this->owner->currentWorkspace;
    }

    private function schedule(string $password = 'password', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/v1/workspace/deletion', ['password' => $password]);
    }

    public function test_the_owner_schedules_the_deletion_with_their_password(): void
    {
        $member = $this->memberOf($this->owner);

        $this->schedule()->assertOk()
            ->assertJsonPath('data.deletion.deletes_at', now()->addDays(30)->toIso8601ZuluString());

        $this->workspace->refresh();
        $this->assertTrue($this->workspace->isPendingDeletion());
        $this->assertSame($this->owner->id, $this->workspace->deletion_requested_by);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PracticeDeletionRequested)->count());
        Notification::assertSentTo([$this->owner, $member], PracticeDeletionScheduled::class);
    }

    public function test_a_wrong_password_a_member_or_a_second_request_are_refused(): void
    {
        $this->schedule('wrong')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->schedule('password', $this->memberOf($this->owner))->assertForbidden();
        $this->assertFalse($this->workspace->refresh()->isPendingDeletion());

        $this->schedule()->assertOk();
        $this->schedule()->assertStatus(409);
    }

    public function test_the_practice_is_closed_while_the_deletion_waits(): void
    {
        $client = Client::factory()->inWorkspace($this->workspace->id)->create();
        $this->schedule()->assertOk();

        $this->actingAs($this->owner)->getJson('/api/v1/clients')
            ->assertForbidden()
            ->assertJsonPath('code', 'practice_pending_deletion');
        $this->actingAs($this->owner)->getJson("/api/v1/clients/{$client->id}")->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertForbidden();

        // What is needed then still answers: who is signed in, the export, cancelling.
        $this->actingAs($this->owner)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.workspace.deletion.deletes_at', now()->addDays(30)->toIso8601ZuluString());
        $this->actingAs($this->owner)->getJson('/api/v1/reference-data')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/v1/workspace/exports')->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/workspace/exports')->assertCreated();
    }

    public function test_cancelling_brings_the_practice_back(): void
    {
        $member = $this->memberOf($this->owner);
        $this->schedule()->assertOk();

        $this->actingAs($member)->deleteJson('/api/v1/workspace/deletion')->assertForbidden();
        $this->actingAs($this->owner)->deleteJson('/api/v1/workspace/deletion')
            ->assertOk()
            ->assertJsonPath('data.deletion', null);

        $this->actingAs($this->owner)->getJson('/api/v1/clients')->assertOk();
        $this->actingAs($this->owner)->deleteJson('/api/v1/workspace/deletion')->assertStatus(409);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PracticeDeletionCancelled)->count());
        Notification::assertSentTo([$this->owner, $member], PracticeDeletionCancelled::class);
    }

    public function test_no_reminders_go_out_from_a_closed_practice(): void
    {
        $client = Client::factory()->inWorkspace($this->workspace->id)->create();
        Appointment::factory()->forClient($client, $this->owner)
            ->at(now()->addMinutes(30)->startOfMinute()->toIso8601ZuluString())
            ->create(['reminder_minutes' => 60]);
        $this->schedule()->assertOk();

        $this->artisan('notifications:send-reminders')->assertSuccessful();

        Notification::assertNotSentTo($this->owner, AppointmentReminder::class);
    }

    public function test_nothing_is_deleted_before_the_day(): void
    {
        $this->schedule()->assertOk();
        $this->travel(29)->days();

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertModelExists($this->workspace);
        $this->assertModelExists($this->owner);
    }

    public function test_on_the_day_everything_goes_with_the_accounts_that_belong_only_to_it(): void
    {
        $client = Client::factory()->inWorkspace($this->workspace->id)->create();
        $service = Service::factory()->create(['workspace_id' => $this->workspace->id]);
        $consultation = Consultation::factory()->forClient($client)->create(['service_id' => $service->id, 'created_by' => $this->owner->id]);
        Appointment::factory()->forClient($client, $this->owner)->create(['service_id' => $service->id]);
        Payment::factory()->forConsultation($consultation, $this->owner)->create();
        Task::factory()->forClient($client, $this->owner)->create();
        $this->actingAs($this->owner)->post('/api/v1/attachments', [
            'client_id' => $client->id,
            'file' => UploadedFile::fake()->createWithContent('Chart.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertCreated();
        $path = DB::table('attachments')->value('storage_path');

        $member = $this->memberOf($this->owner);
        // Someone who also works in another practice keeps their account.
        $elsewhere = User::factory()->withWorkspace()->create();
        $this->workspace->users()->attach($elsewhere, ['role' => WorkspaceRole::Member->value, 'status' => MembershipStatus::Active->value]);

        $this->schedule()->assertOk();
        $this->travel(30)->days();
        $this->travel(1)->minutes();
        $this->app['auth']->forgetGuards();

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertModelMissing($this->workspace);
        $this->assertModelMissing($this->owner);
        $this->assertModelMissing($member);
        $this->assertModelExists($elsewhere);
        foreach (['clients', 'consultations', 'appointments', 'payments', 'tasks', 'attachments', 'services', 'activity_events', 'workspace_user'] as $table) {
            $this->assertSame($table === 'workspace_user' ? 1 : 0, DB::table($table)->count(), $table);
        }
        Storage::disk('attachments')->assertMissing($path);

        $entry = AuditLog::query()->where('event', AuditEvent::PracticeErased)->sole();
        $this->assertNull($entry->user_id);
        $this->assertSame($this->workspace->id, $entry->subject_id);
        $this->assertSame(2, $entry->properties['accounts']);
        // Earlier entries stay until the log's own retention, without the practice.
        $this->assertSame(0, AuditLog::query()->where('workspace_id', $this->workspace->id)->count());

        Notification::assertSentOnDemand(PracticeDeleted::class, function (PracticeDeleted $notification, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === $this->owner->email && $notification->accountDeleted;
        });
        Notification::assertSentOnDemand(PracticeDeleted::class, function (PracticeDeleted $notification, array $channels, AnonymousNotifiable $notifiable) use ($elsewhere) {
            return $notifiable->routes['mail'] === $elsewhere->email && ! $notification->accountDeleted;
        });
    }

    public function test_another_practice_is_untouched(): void
    {
        $other = User::factory()->withWorkspace()->create();
        $theirs = Client::factory()->inWorkspace($other->current_workspace_id)->create();
        $this->schedule()->assertOk();
        $this->travel(31)->days();

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertModelExists($other);
        $this->assertModelExists($theirs);
    }
}
