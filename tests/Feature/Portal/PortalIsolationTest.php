<?php

namespace Tests\Feature\Portal;

use App\Enums\AttachmentKind;
use App\Enums\ClientStatus;
use App\Enums\Visibility;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Note;
use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * The portal shows one client's own data and nothing else (docs/spec/12,
 * "Bezbednost i privatnost"; acceptance criteria 3–6): not another client of
 * the same practice, not another practice, nothing after a revoke, an archive
 * or a closing practice — from the very next request — and the portal's and
 * the application's sessions never open each other.
 */
class PortalIsolationTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private User $vega;

    private Client $ana;

    private Client $boris;

    private PortalUser $user;

    /** @var array<string, int> */
    private array $borisItems = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->vega = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->ana = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'boris@example.com']);
        $this->user = $this->portalUserFor($this->ana);

        // Boris, another client of the same practice, with everything shared with him.
        $this->borisItems = $this->sharedItemsFor($this->boris, $this->vega);
    }

    /**
     * @return array<string, int>
     */
    private function sharedItemsFor(Client $client, User $astrologer): array
    {
        $appointment = Appointment::factory()->forClient($client, $astrologer)->create(['location_details' => "Room of {$client->first_name}"]);
        $note = Note::factory()->forClient($client, $astrologer)->create(['title' => "Note for {$client->first_name}", 'visibility' => Visibility::SharedWithClient]);
        Storage::disk('attachments')->put("{$client->id}/file.pdf", '%PDF');
        $file = (new Attachment)->forceFill([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
            'uploaded_by' => $astrologer->id,
            'attachable_type' => 'client',
            'attachable_id' => $client->id,
            'kind' => AttachmentKind::File,
            'original_name' => "File for {$client->first_name}.pdf",
            'visibility' => Visibility::SharedWithClient,
            'storage_disk' => 'attachments',
            'storage_path' => "{$client->id}/file.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 4,
        ]);
        $file->save();

        return ['appointment' => $appointment->id, 'note' => $note->id, 'file' => $file->id];
    }

    private function assertSeesNothingOf(Client $client, array $items): void
    {
        $body = implode('', [
            $this->portal('GET', 'home')->assertOk()->getContent(),
            $this->portal('GET', 'appointments?when=upcoming')->assertOk()->getContent(),
            $this->portal('GET', 'appointments?when=past')->assertOk()->getContent(),
            $this->portal('GET', 'shared')->assertOk()->getContent(),
            $this->portal('GET', 'profile')->assertOk()->getContent(),
        ]);

        $this->assertStringNotContainsString("Room of {$client->first_name}", $body);
        $this->assertStringNotContainsString("Note for {$client->first_name}", $body);
        $this->assertStringNotContainsString("File for {$client->first_name}", $body);
        $this->assertStringNotContainsString($client->email, $body);
        $this->assertStringNotContainsString('"id":'.$items['appointment'].',', $body);

        $this->portalGet("api/portal/v1/files/{$items['file']}/download")->assertNotFound();
    }

    public function test_another_client_of_the_same_practice_stays_out_of_sight(): void
    {
        $this->signInToPortal($this->user);

        $this->assertSeesNothingOf($this->boris, $this->borisItems);
    }

    public function test_another_practice_stays_out_of_sight(): void
    {
        $other = User::factory()->withWorkspace(['name' => 'Other Practice'])->create();
        $carla = Client::factory()->inWorkspace($other->current_workspace_id)->create(['first_name' => 'Carla', 'email' => 'carla@example.com']);
        $items = $this->sharedItemsFor($carla, $other);

        $this->signInToPortal($this->user);

        $this->assertSeesNothingOf($carla, $items);
        $this->assertStringNotContainsString('Other Practice', $this->portal('GET', 'session')->getContent());
    }

    public function test_revoking_closes_the_portal_from_the_next_request(): void
    {
        $this->signInToPortal($this->user);
        $this->portal('GET', 'home')->assertOk();

        $this->actingAs($this->vega)->deleteJson("/api/v1/clients/{$this->ana->id}/portal")->assertOk();

        foreach (['home', 'appointments', 'shared', 'profile'] as $screen) {
            $this->portal('GET', $screen)->assertForbidden()->assertJsonPath('code', 'portal_no_access');
        }
        $this->portal('GET', 'session')->assertJsonPath('data.state', 'no_access')->assertJsonPath('data.practices', []);
    }

    public function test_archiving_the_client_pauses_access_and_restoring_brings_it_back(): void
    {
        $this->signInToPortal($this->user);

        $this->ana->update(['status' => ClientStatus::Archived]);
        $this->portal('GET', 'home')->assertForbidden()->assertJsonPath('code', 'portal_no_access');

        $this->actingAs($this->vega)->getJson("/api/v1/clients/{$this->ana->id}/portal")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.paused', true);

        $this->ana->update(['status' => ClientStatus::Active]);
        $this->portal('GET', 'home')->assertOk();
    }

    public function test_a_practice_scheduled_for_deletion_closes_the_portal(): void
    {
        $this->signInToPortal($this->user);

        DB::table('workspaces')->where('id', $this->ana->workspace_id)->update(['deletes_at' => now()->addDays(30)]);

        $this->portal('GET', 'shared')->assertForbidden()->assertJsonPath('code', 'portal_no_access');
    }

    public function test_one_account_with_two_practices_sees_them_apart(): void
    {
        $other = User::factory()->withWorkspace(['name' => 'Other Practice'])->create();
        $anaThere = Client::factory()->inWorkspace($other->current_workspace_id)->create(['first_name' => 'Ana', 'email' => 'ana@example.com']);
        $itemsThere = $this->sharedItemsFor($anaThere, $other);
        $itemsHere = $this->sharedItemsFor($this->ana, $this->vega);
        $this->portalUserFor($anaThere, $this->user);

        $session = $this->signInToPortal($this->user)->assertJsonPath('data.state', 'choose')->assertJsonCount(2, 'data.practices');
        $this->portal('GET', 'home')->assertForbidden()->assertJsonPath('code', 'portal_choose_practice');

        $practices = collect($session->json('data.practices'));
        $here = $practices->firstWhere('name', 'Vega Practice')['id'];
        $there = $practices->firstWhere('name', 'Other Practice')['id'];

        $this->portal('PUT', 'practice', ['practice_id' => $here])->assertOk()->assertJsonPath('data.current_practice_id', $here);
        $shared = $this->portal('GET', 'shared')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            ["note-{$itemsHere['note']}", "file-{$itemsHere['file']}"],
            collect($shared)->map(fn (array $item) => "{$item['type']}-{$item['id']}")->all(),
        );
        $this->portalGet("api/portal/v1/files/{$itemsThere['file']}/download")->assertNotFound();

        $this->portal('PUT', 'practice', ['practice_id' => $there])->assertOk();
        $this->portal('GET', 'home')->assertJsonPath('data.practice.name', 'Other Practice');
        $this->portalGet("api/portal/v1/files/{$itemsHere['file']}/download")->assertNotFound();

        // A link that is not the person's own cannot be chosen.
        $borisAccount = $this->portalUserFor($this->boris);
        $this->portal('PUT', 'practice', ['practice_id' => $borisAccount->accesses()->value('id')])->assertUnprocessable();
    }

    public function test_an_astrologers_session_does_not_open_the_portal(): void
    {
        $this->actingAs($this->vega);

        $this->getJson($this->portalUrl('api/portal/v1/home'))->assertUnauthorized();
        $this->getJson($this->portalUrl('api/portal/v1/session'))->assertJsonPath('data.state', 'guest');
    }

    public function test_a_portal_session_does_not_open_the_application(): void
    {
        $this->signInToPortal($this->user);

        // Same cookies, the application's host: nobody is signed in there.
        $this->withCredentials()
            ->withUnencryptedCookie(config('portal.session.cookie'), $this->portalCookie())
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_the_applications_routes_do_not_exist_on_the_portal_host(): void
    {
        $this->getJson($this->portalUrl('api/v1/status'))->assertNotFound();
        $this->getJson($this->portalUrl('sanctum/csrf-cookie'))->assertNotFound();
        $this->postJson($this->portalUrl('api/v1/auth/login'), ['email' => $this->vega->email, 'password' => 'password'])->assertNotFound();
        $this->getJson($this->portalUrl('api/v1/clients'))->assertNotFound();
    }

    public function test_the_portals_routes_do_not_exist_on_the_application_host(): void
    {
        $this->getJson('/api/portal/v1/session')->assertNotFound();
        $this->postJson('/api/portal/v1/sign-in', ['email' => 'ana@example.com'])->assertNotFound();
    }

    public function test_the_portal_page_is_its_own_spa(): void
    {
        $this->portalGet('appointments')
            ->assertOk()
            ->assertSee('id="portal"', false)
            ->assertDontSee('id="app"', false)
            ->assertHeader('Content-Security-Policy');
    }
}
