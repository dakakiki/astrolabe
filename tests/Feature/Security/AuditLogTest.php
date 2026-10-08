<?php

namespace Tests\Feature\Security;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    /**
     * @return Collection<int, AuditLog>
     */
    private function entries(AuditEvent $event): Collection
    {
        return AuditLog::query()->where('event', $event)->get();
    }

    public function test_sign_ins_are_recorded_with_where_they_came_from(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Firefox/131.0')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $this->post('/api/v1/auth/logout');

        $login = $this->entries(AuditEvent::Login)->sole();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('127.0.0.1', $login->ip_address);
        $this->assertSame('Mozilla/5.0 (Macintosh) Firefox/131.0', $login->user_agent);
        $this->assertNull($login->workspace_id);
        $this->assertSame($user->id, $this->entries(AuditEvent::Logout)->sole()->user_id);
    }

    public function test_failed_attempts_name_the_account_but_never_the_attempt(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'guess-1'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'guess-2'])->assertUnprocessable();

        $failed = $this->entries(AuditEvent::LoginFailed);
        $this->assertSame([$user->id, null], $failed->pluck('user_id')->all());
        $this->assertNull($failed->first()->properties);
        $this->assertStringNotContainsString('guess', AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('nobody@example.com', AuditLog::query()->get()->toJson());

        // Five attempts a minute; the sixth and seventh are refused, one lockout is recorded.
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'guess']);
        }

        $this->assertSame([$user->id], $this->entries(AuditEvent::Lockout)->pluck('user_id')->all());
    }

    public function test_account_changes_are_recorded_without_their_values(): void
    {
        Notification::fake();
        $user = User::factory()->withWorkspace()->create(['email' => 'old@example.com']);

        $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'password',
            'password' => 'another-long-password',
            'password_confirmation' => 'another-long-password',
        ])->assertOk();
        $this->actingAs($user)->putJson('/api/v1/auth/user/profile-information', ['email' => 'new@example.com'])->assertOk();
        $this->actingAs($user)->putJson('/api/v1/auth/user/profile-information', ['name' => 'Only the name'])->assertOk();

        $this->assertCount(1, $this->entries(AuditEvent::PasswordChanged));
        $this->assertCount(1, $this->entries(AuditEvent::EmailChanged));
        $log = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('old@example.com', $log);
        $this->assertStringNotContainsString('new@example.com', $log);
    }

    public function test_signing_out_other_sessions_is_recorded(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->deleteJson('/api/v1/auth/other-sessions', ['password' => 'password'])->assertNoContent();

        $this->assertSame($user->id, $this->entries(AuditEvent::OtherSessionsSignedOut)->sole()->user_id);
    }

    public function test_exports_downloads_deletions_and_practice_settings_are_recorded(): void
    {
        Storage::fake('attachments');
        $user = User::factory()->withWorkspace()->create();
        $workspaceId = $user->current_workspace_id;
        $client = Client::factory()->inWorkspace($workspaceId)->create(['first_name' => 'Ana']);
        $consultation = Consultation::factory()->forClient($client)->create();
        $note = Note::factory()->forClient($client, $user)->create();

        $this->actingAs($user)->get('/api/v1/payments/export?client_id='.$client->id.'&search=Ana')->assertOk()->streamedContent();

        $file = $this->actingAs($user)->post('/api/v1/attachments', [
            'client_id' => $client->id,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Private text'),
        ], ['Accept' => 'application/json'])->json('data.id');
        $this->actingAs($user)->get("/api/v1/attachments/{$file}/download")->assertOk();

        $this->actingAs($user)->deleteJson("/api/v1/consultations/{$consultation->id}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/notes/{$note->id}")->assertNoContent();
        $this->actingAs($user)->patchJson('/api/v1/workspace', ['name' => 'Renamed practice'])->assertOk();

        $export = $this->entries(AuditEvent::PaymentsExported)->sole();
        $this->assertSame(['client_id', 'search'], $export->properties['filters']);
        $this->assertSame($workspaceId, $export->workspace_id);

        $download = $this->entries(AuditEvent::FileDownloaded)->sole();
        $this->assertSame(['attachment', $file], [$download->subject_type, $download->subject_id]);

        $this->assertEqualsCanonicalizing(
            [['consultation', $consultation->id], ['note', $note->id]],
            $this->entries(AuditEvent::RecordDeleted)->map(fn (AuditLog $entry) => [$entry->subject_type, $entry->subject_id])->all(),
        );
        $this->assertSame(['name'], $this->entries(AuditEvent::PracticeSettingsChanged)->sole()->properties['fields']);

        // Names, never values.
        $log = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('Ana', $log);
        $this->assertStringNotContainsString('Renamed practice', $log);
    }

    public function test_astrologers_no_longer_read_the_audit_log(): void
    {
        // Phase 8c: "Recent security activity" moved to the operator's admin.
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->getJson('/api/v1/security-activity')->assertNotFound();
        $this->actingAs($user)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }
}
