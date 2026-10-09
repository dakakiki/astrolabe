<?php

namespace Tests\Feature\Portal;

use App\Actions\Clients\DeleteClient;
use App\Actions\Workspaces\DeletePractice;
use App\Enums\PortalAccessStatus;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalInvitation;
use App\Models\PortalLoginToken;
use App\Models\PortalUser;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DataExport\PracticeExport;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;
use ZipArchive;

/**
 * The portal in the data lifecycle (docs/spec/12, "Bezbednost i privatnost";
 * Phase 8b): deleting a client or a practice takes the links and the accounts
 * nothing else opens, `data:prune` clears tokens, invitations, sessions and
 * forgotten accounts, and the practice export lists the links.
 */
class PortalDataLifecycleTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private User $vega;

    private Client $ana;

    private PortalUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('attachments');
        Storage::fake('exports');
        $this->vega = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->ana = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($this->ana);
    }

    private function inPractice(callable $callback): mixed
    {
        return app(CurrentWorkspace::class)->run(Workspace::query()->findOrFail($this->vega->current_workspace_id), $callback);
    }

    public function test_deleting_the_client_takes_the_link_and_the_account_with_its_sessions(): void
    {
        $this->signInToPortal($this->user);
        $this->assertSame(1, DB::table('portal_sessions')->count());

        $this->actingAs($this->vega);
        $counts = $this->inPractice(fn () => app(DeleteClient::class)->handle($this->ana));

        $this->assertSame(1, $counts['portal_links']);
        $this->assertSame(1, $counts['portal_accounts']);
        $this->assertSame(0, PortalUser::query()->count());
        $this->assertSame(0, PortalAccess::query()->acrossPractices()->count());
        $this->assertSame(0, DB::table('portal_sessions')->count());
        $this->assertSame(0, PortalLoginToken::query()->count());
    }

    public function test_an_account_another_practice_still_opens_stays(): void
    {
        $other = User::factory()->withWorkspace()->create();
        $anaThere = Client::factory()->inWorkspace($other->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->portalUserFor($anaThere, $this->user);

        $this->actingAs($this->vega);
        $this->inPractice(fn () => app(DeleteClient::class)->handle($this->ana));

        $this->assertNotNull(PortalUser::query()->find($this->user->id));
        $this->assertSame(1, PortalAccess::query()->acrossPractices()->count());
    }

    public function test_deleting_the_practice_takes_its_links_and_their_accounts(): void
    {
        DB::table('workspaces')->where('id', $this->vega->current_workspace_id)->update(['deletes_at' => now()]);

        $counts = app(DeletePractice::class)->handle(Workspace::query()->findOrFail($this->vega->current_workspace_id));

        $this->assertSame(1, $counts['portal_access']);
        $this->assertSame(1, $counts['portal_accounts']);
        $this->assertSame(0, PortalUser::query()->count());
    }

    public function test_prune_clears_tokens_invitations_sessions_and_forgotten_accounts(): void
    {
        // A used sign-in token from two days ago, and one still open.
        PortalLoginToken::issue($this->user, Request::create('/'));
        DB::table('portal_login_tokens')->update(['used_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
        PortalLoginToken::issue($this->user, Request::create('/'));

        // An expired invitation from long ago, and an old session.
        $access = PortalAccess::query()->acrossPractices()->sole();
        [$old] = PortalInvitation::issue($access);
        $old->forceFill(['expires_at' => now()->subDays(40)])->save();
        DB::table('portal_sessions')->insert(['id' => 'old-session', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => now()->subDays(31)->getTimestamp()]);

        // Bob's link was revoked 40 days ago: his account goes; Ana's link is active: hers stays.
        $boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'boris@example.com']);
        $bob = $this->portalUserFor($boris);
        PortalAccess::query()->acrossPractices()->where('portal_user_id', $bob->id)->update(['status' => PortalAccessStatus::Revoked->value, 'revoked_at' => now()->subDays(40)]);
        DB::table('portal_users')->where('id', $bob->id)->update(['created_at' => now()->subDays(60)]);
        DB::table('portal_users')->where('id', $this->user->id)->update(['created_at' => now()->subDays(60)]);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(1, PortalLoginToken::query()->count());
        $this->assertSame(0, PortalInvitation::query()->count());
        $this->assertSame(0, DB::table('portal_sessions')->where('id', 'old-session')->count());
        $this->assertNull(PortalUser::query()->find($bob->id));
        $this->assertNotNull(PortalUser::query()->find($this->user->id));
        // The revoked link stays as the practice's history, without the account.
        $this->assertNull(PortalAccess::query()->acrossPractices()->where('client_id', $boris->id)->sole()->portal_user_id);
    }

    public function test_the_practice_export_lists_the_portal_links_without_secrets(): void
    {
        [, $token] = PortalInvitation::issue(PortalAccess::query()->acrossPractices()->sole());
        $path = Storage::disk('exports')->path('portal-test.zip');
        @mkdir(dirname($path), 0777, true);

        app(PracticeExport::class)->write(Workspace::query()->findOrFail($this->vega->current_workspace_id), $path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $links = json_decode($zip->getFromName('portal_access.json'), true);
        $everything = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $everything .= $zip->getFromIndex($i);
        }
        $zip->close();

        $this->assertSame('ana@example.com', $links[0]['email']);
        $this->assertSame('active', $links[0]['status']);
        $this->assertSame($this->ana->id, $links[0]['client_id']);
        $this->assertStringNotContainsString($token, $everything);
        $this->assertStringNotContainsString(hash('sha256', $token), $everything);
    }
}
