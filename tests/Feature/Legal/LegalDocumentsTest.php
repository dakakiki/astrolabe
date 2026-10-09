<?php

namespace Tests\Feature\Legal;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Terms of Service, Data Processing Agreement and Privacy Policy (Phase 8c):
 * public documents with versions, accepted at registration and again when a
 * new version of the Terms or the DPA is published.
 */
class LegalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            File::deleteDirectory($this->path);
        }
        LegalDocuments::flush();

        parent::tearDown();
    }

    /**
     * The repository's documents in a scratch folder, plus the given files
     * (`terms/2026-12-01.md` => contents), as the documents in force.
     *
     * @param  array<string, string>  $files
     */
    private function publish(array $files): void
    {
        $this->path ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'astrolabe-legal-'.uniqid();
        if (! File::isDirectory($this->path)) {
            File::copyDirectory(resource_path('legal'), $this->path);
        }

        foreach ($files as $name => $contents) {
            File::ensureDirectoryExists(dirname($this->path.'/'.$name));
            File::put($this->path.'/'.$name, $contents);
        }

        config(['astrolabe.legal.path' => $this->path]);
        LegalDocuments::flush();
    }

    private function newTerms(string $version = '2026-12-01'): void
    {
        $this->publish(["terms/{$version}.md" => "---\ntitle: Terms of Service\neffective: {$version}\nsummary: Clearer cancellation.\n---\n\n## 1. Changed\n\nNew text.\n"]);
    }

    public function test_the_documents_in_force_are_public(): void
    {
        $this->getJson('/api/v1/legal')
            ->assertOk()
            ->assertJsonPath('data.*.slug', ['terms', 'dpa', 'privacy'])
            ->assertJsonPath('data.0.requires_acceptance', true)
            ->assertJsonPath('data.2.requires_acceptance', false)
            ->assertJsonPath('data.0.draft', true);

        $terms = $this->getJson('/api/v1/legal/terms')->assertOk();
        $this->assertSame('Terms of Service', $terms->json('data.title'));
        $this->assertTrue($terms->json('data.current'));
        $this->assertContains('1. Who we are', array_column($terms->json('data.sections'), 'title'));
        $this->assertStringContainsString('<h2 id="1-who-we-are">', $terms->json('data.html'));

        $this->getJson('/api/v1/legal/cookies')->assertNotFound();
        $this->getJson('/api/v1/legal/terms?version=1999-01-01')->assertNotFound();
    }

    public function test_the_texts_never_mention_the_ephemeris_authors(): void
    {
        foreach (File::allFiles(resource_path('legal')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/astrodienst|treindl|dieter koch|aloistr/i', $file->getContents(), $file->getRelativePathname());
        }
    }

    public function test_raw_html_and_unsafe_links_are_stripped(): void
    {
        $this->publish(['privacy/2026-12-01.md' => "---\ntitle: Privacy Policy\n---\n\n<script>alert(1)</script>\n\n[click](javascript:alert(1)) and [ok](/legal/terms)\n"]);

        $html = $this->getJson('/api/v1/legal/privacy')->assertOk()->json('data.html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="/legal/terms"', $html);
    }

    public function test_an_earlier_version_stays_readable(): void
    {
        $this->newTerms();

        $this->getJson('/api/v1/legal/terms')
            ->assertJsonPath('data.version', '2026-12-01')
            ->assertJsonPath('data.versions.*.version', ['2026-12-01', '2026-10-09']);

        $this->getJson('/api/v1/legal/terms?version=2026-10-09')
            ->assertOk()
            ->assertJsonPath('data.version', '2026-10-09')
            ->assertJsonPath('data.current', false);
    }

    public function test_registration_records_the_versions_accepted(): void
    {
        [, $token] = RegistrationInvitation::issue('nova@example.com', 14);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nova Astrologer',
            'email' => 'nova@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'invitation' => $token,
            'accept_terms' => true,
            'legal' => LegalDocuments::currentVersions(),
        ], ['REMOTE_ADDR' => '203.0.113.9'])->assertCreated();

        $user = User::query()->where('email', 'nova@example.com')->firstOrFail();
        $rows = LegalAcceptance::query()->where('user_id', $user->id)->orderBy('document')->get();

        $this->assertSame(['dpa', 'privacy', 'terms'], $rows->pluck('document')->all());
        $this->assertSame(['2026-10-09'], $rows->pluck('version')->unique()->values()->all());
        $this->assertSame('203.0.113.9', $rows->first()->ip_address);

        $audit = AuditLog::query()->where('event', AuditEvent::LegalAccepted)->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(LegalDocuments::currentVersions(), $audit->properties['documents']);

        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.user.legal.pending', [])
            ->assertJsonPath('data.user.legal.updated', [])
            ->assertJsonPath('data.user.legal.accepted.terms.version', '2026-10-09');
    }

    public function test_registration_needs_the_terms_accepted_in_the_current_versions(): void
    {
        [, $token] = RegistrationInvitation::issue('nova@example.com', 14);
        $form = [
            'name' => 'Nova Astrologer',
            'email' => 'nova@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'invitation' => $token,
            'legal' => LegalDocuments::currentVersions(),
        ];

        $this->postJson('/api/v1/auth/register', $form)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accept_terms' => 'accept the Terms']);

        // The Terms changed while the form was open: nothing is accepted unread.
        $this->newTerms();
        $this->postJson('/api/v1/auth/register', $form + ['accept_terms' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accept_terms' => 'just been updated']);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('legal_acceptances', 0);
        $this->assertNull(RegistrationInvitation::query()->sole()->accepted_at);
    }

    public function test_new_terms_close_the_practice_until_they_are_accepted(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $this->actingAs($user);

        $this->getJson('/api/v1/clients')->assertOk();

        $this->newTerms();

        $this->getJson('/api/v1/clients')
            ->assertForbidden()
            ->assertJsonPath('code', 'legal_acceptance_required')
            ->assertJsonPath('documents', ['terms']);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.legal.pending', ['terms']);

        // Still open: the export and leaving the practice.
        $this->getJson('/api/v1/workspace/exports')->assertOk();
        $this->getJson('/api/v1/workspace')->assertOk();

        // Accepting is on purpose, in the version shown.
        $this->postJson('/api/v1/legal/acceptances', ['documents' => ['terms' => '2026-12-01']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept');
        $this->postJson('/api/v1/legal/acceptances', ['documents' => ['terms' => '2026-10-09'], 'accept' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');

        $this->postJson('/api/v1/legal/acceptances', ['documents' => ['terms' => '2026-12-01'], 'accept' => true])
            ->assertOk()
            ->assertJsonPath('data.legal.pending', [])
            ->assertJsonPath('data.legal.accepted.terms.version', '2026-12-01');

        $this->getJson('/api/v1/clients')->assertOk();
        $this->assertSame(2, LegalAcceptance::query()->where('user_id', $user->id)->where('document', 'terms')->count());
    }

    public function test_a_new_privacy_policy_is_a_notice_that_blocks_nothing(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $this->actingAs($user);

        $this->publish(['privacy/2026-12-01.md' => "---\ntitle: Privacy Policy\n---\n\nNew.\n"]);

        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.user.legal.pending', [])
            ->assertJsonPath('data.user.legal.updated', ['privacy'])
            ->assertJsonPath('data.user.legal.current.privacy', '2026-12-01');

        // Dismissing the notice needs no "accept".
        $this->postJson('/api/v1/legal/acceptances', ['documents' => ['privacy' => '2026-12-01']])
            ->assertOk()
            ->assertJsonPath('data.legal.updated', []);
    }

    public function test_only_known_documents_can_be_accepted(): void
    {
        $this->actingAs(User::factory()->withWorkspace()->create());

        $this->postJson('/api/v1/legal/acceptances', ['documents' => ['cookies' => '2026-10-09'], 'accept' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');
        $this->postJson('/api/v1/legal/acceptances', [])->assertUnprocessable();
    }

    public function test_the_admin_accepts_nothing(): void
    {
        $this->newTerms();
        $admin = User::factory()->admin()->create();

        $this->assertDatabaseMissing('legal_acceptances', ['user_id' => $admin->id]);
        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.user.legal', null);
        $this->getJson('/api/v1/admin/astrologers')->assertOk();
    }

    public function test_acceptances_go_with_the_account(): void
    {
        $user = User::factory()->create();
        $this->assertSame(3, LegalAcceptance::query()->where('user_id', $user->id)->count());

        $user->delete();

        $this->assertDatabaseCount('legal_acceptances', 0);
    }
}
