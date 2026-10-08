<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Feedback;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Notifications\OperatorAlert;
use App\Notifications\RegistrationInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The admin's audit log, invitations, system and feedback screens, and the
 * astrologers' "Feedback" button (Phase 8c).
 */
class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->admin()->create(['timezone' => 'Europe/Belgrade']);
    }

    public function test_the_audit_log_shows_everyone_and_filters(): void
    {
        $mila = User::factory()->withWorkspace()->create(['email' => 'mila@example.com']);
        $luka = User::factory()->withWorkspace()->create(['email' => 'luka@example.com']);
        AuditLog::query()->create(['event' => AuditEvent::Login, 'user_id' => $mila->id, 'created_at' => now()->subDays(2)]);
        AuditLog::query()->create(['event' => AuditEvent::LoginFailed, 'user_id' => $mila->id, 'created_at' => now()->subDay()]);
        AuditLog::query()->create(['event' => AuditEvent::Login, 'user_id' => $luka->id, 'workspace_id' => $luka->current_workspace_id, 'created_at' => now()]);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.user.email', 'luka@example.com')
            ->assertJsonPath('data.1.event', 'login_failed')
            ->assertJsonPath('data.1.warning', true);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/audit-logs?user_id={$mila->id}")->assertJsonCount(2, 'data');
        // What the operator did to the account belongs to it too.
        AuditLog::query()->create(['event' => AuditEvent::AccountSuspended, 'user_id' => $this->admin->id, 'subject_type' => 'user', 'subject_id' => $mila->id, 'created_at' => now()]);
        $this->actingAs($this->admin)->getJson("/api/v1/admin/audit-logs?user_id={$mila->id}")->assertJsonCount(4, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/audit-logs?warnings=1')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/audit-logs?email=luka')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("/api/v1/admin/audit-logs?workspace_id={$luka->current_workspace_id}")->assertJsonCount(1, 'data');

        // Reading the log is recorded too, with the filter's name and the person looked at.
        $look = AuditLog::query()->where('event', AuditEvent::AdminViewed)->where('subject_id', $mila->id)->orderBy('id')->firstOrFail();
        $this->assertSame(['screen' => 'audit_log', 'filters' => ['user_id']], $look->properties);
    }

    public function test_invitations_are_sent_listed_and_revoked_from_the_admin(): void
    {
        User::factory()->withWorkspace()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin)->postJson('/api/v1/admin/invitations', ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/invitations', ['email' => 'New.Tester@Example.com', 'note' => 'School'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.tester@example.com')
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('meta.sent', true);
        $this->assertStringContainsString('/register?invitation=', $response->json('meta.link'));

        Notification::assertSentOnDemand(RegistrationInvite::class);
        $invitation = RegistrationInvitation::query()->sole();
        $this->assertSame($this->admin->id, AuditLog::query()->where('event', AuditEvent::InvitationSent)->sole()->user_id);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/invitations')->assertJsonPath('data.0.note', 'School');
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/invitations/{$invitation->id}")->assertJsonPath('data.status', 'revoked');
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/invitations/{$invitation->id}")->assertStatus(409);
    }

    public function test_the_system_screen_names_failed_jobs_without_their_contents(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'job-1', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Notifications\\AppointmentReminder', 'data' => 'Ana Marković']),
            'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[23000] … 'Ana Marković' …\n#0 trace",
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonStructure(['data' => ['checks', 'versions' => ['app', 'php', 'laravel', 'database', 'engine'], 'database', 'storage', 'backups', 'queue', 'failed_jobs']])
            ->assertJsonPath('data.failed_jobs.0.job', 'AppointmentReminder')
            ->assertJsonPath('data.failed_jobs.0.exception', 'Illuminate\\Database\\QueryException')
            ->assertJsonPath('data.queue.failed', 1);
        $this->assertStringNotContainsString('Ana', $response->getContent());

        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/failed-jobs/job-1')->assertNoContent();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->actingAs($this->admin)->postJson('/api/v1/admin/failed-jobs/job-1/retry')->assertNotFound();
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::AdminJobDeleted)->count());
    }

    public function test_feedback_reaches_the_admin_and_the_operator_hears_of_it_without_the_text(): void
    {
        config(['astrolabe.operator.email' => 'operator@example.com']);
        $mila = User::factory()->withWorkspace()->create();

        $this->actingAs($mila)->postJson('/api/v1/feedback', ['category' => 'nope', 'message' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'message']);
        $this->actingAs($mila)->withHeader('User-Agent', 'Firefox/131.0')->postJson('/api/v1/feedback', [
            'category' => 'bug',
            'message' => 'The transit list for Ana is empty on Tuesdays.',
            'page' => '/clients/12/edit?tab=transits&search=Ana',
        ])->assertCreated();

        $feedback = Feedback::query()->sole();
        $this->assertSame('/clients/:id/edit', $feedback->page);
        $this->assertSame($mila->current_workspace_id, $feedback->workspace_id);
        $this->assertSame('Firefox/131.0', $feedback->user_agent);

        Notification::assertSentOnDemand(OperatorAlert::class, function (OperatorAlert $alert, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === 'operator@example.com'
                && ! str_contains(implode(' ', [$alert->subject, ...$alert->lines]), 'Ana');
        });

        $this->actingAs($this->admin)->getJson('/api/v1/admin/feedback')
            ->assertOk()
            ->assertJsonPath('meta.open', 1)
            ->assertJsonPath('data.0.message', 'The transit list for Ana is empty on Tuesdays.')
            ->assertJsonPath('data.0.user.id', $mila->id);
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['handled' => true])->assertOk();
        $this->actingAs($this->admin)->getJson('/api/v1/admin/feedback')->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/feedback?status=handled')->assertJsonCount(1, 'data');

        // The author's words go with their account.
        $mila->delete();
        $this->assertSame(0, Feedback::query()->count());
    }

    public function test_every_audit_event_has_a_label_in_the_admin(): void
    {
        $labels = json_decode((string) file_get_contents(resource_path('js/i18n/locales/en.json')), true)['admin']['events'];

        foreach (AuditEvent::cases() as $event) {
            $this->assertArrayHasKey($event->value, $labels, "admin.events.{$event->value} is missing");
        }
    }

    public function test_astrologers_cannot_read_the_inbox(): void
    {
        $mila = User::factory()->withWorkspace()->create();

        $this->actingAs($mila)->getJson('/api/v1/admin/feedback')->assertForbidden();
        $this->actingAs($mila)->getJson('/api/v1/admin/invitations')->assertForbidden();
    }
}
