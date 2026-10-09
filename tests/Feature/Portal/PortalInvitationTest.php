<?php

namespace Tests\Feature\Portal;

use App\Enums\AuditEvent;
use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalInvitation;
use App\Models\PortalUser;
use App\Models\User;
use App\Notifications\PortalInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * Inviting a client to the portal and accepting (docs/spec/12, "Pozivnica";
 * acceptance criterion 1): only through an invitation, for a week, once, and a
 * new one replaces the old.
 */
class PortalInvitationTest extends TestCase
{
    use AddsWorkspaceMembers, InteractsWithPortal, RefreshDatabase;

    private User $astrologer;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->astrologer = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->client = Client::factory()->inWorkspace($this->astrologer->current_workspace_id)->create([
            'first_name' => 'Ana',
            'last_name' => 'Client',
            'email' => 'Ana.Client@Example.com',
        ]);
    }

    /** Invites the client and returns the token from the email. */
    private function invite(?User $by = null): string
    {
        $this->actingAs($by ?? $this->astrologer)
            ->postJson("/api/v1/clients/{$this->client->id}/portal/invitation")
            ->assertOk()
            ->assertJsonPath('data.status', 'invited');

        $token = null;
        Notification::assertSentOnDemand(PortalInvite::class, function (PortalInvite $mail, array $channels, AnonymousNotifiable $notifiable) use (&$token) {
            $url = $mail->toMail($notifiable)->actionUrl;
            $token = substr($url, strpos($url, '#') + 1);

            return $notifiable->routes['mail'] === 'ana.client@example.com';
        });

        return $token;
    }

    public function test_the_client_card_starts_with_no_access(): void
    {
        $this->actingAs($this->astrologer)->getJson("/api/v1/clients/{$this->client->id}/portal")
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.can_invite', true)
            ->assertJsonPath('data.portal_url', 'http://portal.astrolabe.test');
    }

    public function test_an_invitation_goes_to_the_clients_address_with_a_link_to_the_portal(): void
    {
        $this->invite();

        $access = PortalAccess::query()->acrossPractices()->sole();
        $this->assertSame(PortalAccessStatus::Invited, $access->status);
        $this->assertSame('ana.client@example.com', $access->email);
        $this->assertNull($access->portal_user_id);

        $invitation = PortalInvitation::query()->sole();
        $this->assertNotNull($invitation->sent_at);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalInvitationSent)->where('user_id', $this->astrologer->id)->count());

        Notification::assertSentOnDemand(PortalInvite::class, function (PortalInvite $mail, array $channels, AnonymousNotifiable $notifiable) {
            $message = $mail->toMail($notifiable);

            return str_starts_with($message->actionUrl, 'http://portal.astrolabe.test/invitation#')
                && str_contains($message->subject, 'Vega Practice');
        });
    }

    public function test_a_client_without_an_email_address_or_archived_cannot_be_invited(): void
    {
        $this->client->update(['email' => null]);
        $this->actingAs($this->astrologer)->postJson("/api/v1/clients/{$this->client->id}/portal/invitation")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->client->update(['email' => 'ana@example.com', 'status' => ClientStatus::Archived]);
        $this->actingAs($this->astrologer)->postJson("/api/v1/clients/{$this->client->id}/portal/invitation")
            ->assertUnprocessable();

        $this->assertSame(0, PortalAccess::query()->acrossPractices()->count());
    }

    public function test_the_invitation_page_shows_the_practice_and_accepting_signs_the_client_in(): void
    {
        $token = $this->invite();

        $this->portal('POST', 'invitations/preview', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.practice.name', 'Vega Practice')
            ->assertJsonPath('data.email', 'a•••@example.com')
            ->assertJsonPath('data.for_signed_in', false);

        // Looking at the page used nothing up.
        $this->assertNull(PortalInvitation::query()->sole()->used_at);

        $this->portal('POST', 'invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.user.email', 'ana.client@example.com')
            ->assertJsonPath('data.practices.0.name', 'Vega Practice');

        $user = PortalUser::query()->sole();
        $access = PortalAccess::query()->acrossPractices()->sole();
        $this->assertSame(PortalAccessStatus::Active, $access->status);
        $this->assertSame($user->id, $access->portal_user_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull(PortalInvitation::query()->sole()->used_at);

        // The session holds: the next request is signed in.
        $this->portal('GET', 'home')->assertOk()->assertJsonPath('data.practice.name', 'Vega Practice');

        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalAccessAccepted)->where('portal_user_id', $user->id)->whereNull('user_id')->count());
    }

    public function test_an_invitation_works_once(): void
    {
        $token = $this->invite();
        $this->portal('POST', 'invitations/accept', ['token' => $token])->assertOk();

        $this->forgetPortalCookie();
        $this->portal('POST', 'invitations/preview', ['token' => $token])->assertJsonPath('data.status', 'used');
        $this->portal('POST', 'invitations/accept', ['token' => $token])->assertUnprocessable();
        $this->portal('GET', 'home')->assertUnauthorized();
    }

    public function test_an_invitation_expires_after_a_week(): void
    {
        $token = $this->invite();

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->portal('POST', 'invitations/preview', ['token' => $token])->assertJsonPath('data.status', 'expired');
        $this->portal('POST', 'invitations/accept', ['token' => $token])->assertUnprocessable();
        $this->assertSame(0, PortalUser::query()->count());
    }

    public function test_a_new_invitation_replaces_the_old_one(): void
    {
        $first = $this->invite();
        Notification::fake();
        $second = $this->invite();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, PortalAccess::query()->acrossPractices()->count());

        $this->portal('POST', 'invitations/preview', ['token' => $first])->assertJsonPath('data.status', 'revoked');
        $this->portal('POST', 'invitations/accept', ['token' => $first])->assertUnprocessable();
        $this->portal('POST', 'invitations/accept', ['token' => $second])->assertOk();
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->portal('POST', 'invitations/preview', ['token' => str_repeat('x', 48)])->assertNotFound();
        $this->portal('POST', 'invitations/accept', ['token' => str_repeat('x', 48)])->assertNotFound();
    }

    public function test_revoking_withdraws_an_open_invitation(): void
    {
        $token = $this->invite();

        $this->actingAs($this->astrologer)->deleteJson("/api/v1/clients/{$this->client->id}/portal")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.can_invite', true);

        $this->portal('POST', 'invitations/accept', ['token' => $token])->assertUnprocessable();
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalInvitationRevoked)->count());
    }

    public function test_an_active_client_cannot_be_invited_twice_but_can_be_invited_again_after_revoking(): void
    {
        $this->portal('POST', 'invitations/accept', ['token' => $this->invite()])->assertOk();

        $this->actingAs($this->astrologer)->postJson("/api/v1/clients/{$this->client->id}/portal/invitation")->assertConflict();

        $this->actingAs($this->astrologer)->deleteJson("/api/v1/clients/{$this->client->id}/portal")->assertOk();
        Notification::fake();
        $token = $this->invite();

        $this->assertSame(2, PortalAccess::query()->acrossPractices()->count());

        // Still signed in on this device: the page knows the invitation is for this account.
        $this->portal('POST', 'invitations/preview', ['token' => $token])->assertJsonPath('data.for_signed_in', true);

        // Accepting again finds the same portal account by its address.
        $this->forgetPortalCookie();
        $this->portal('POST', 'invitations/accept', ['token' => $token])->assertOk();
        $this->assertSame(1, PortalUser::query()->count());
    }

    public function test_every_member_who_works_with_the_client_may_invite(): void
    {
        $member = $this->memberOf($this->astrologer);

        $this->invite($member);

        $this->assertSame($member->id, PortalAccess::query()->acrossPractices()->sole()->invited_by);
    }

    public function test_another_practice_cannot_invite_or_see_the_client(): void
    {
        $other = User::factory()->withWorkspace()->create();

        $this->actingAs($other)->getJson("/api/v1/clients/{$this->client->id}/portal")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/clients/{$this->client->id}/portal/invitation")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/clients/{$this->client->id}/portal")->assertNotFound();
    }

    public function test_an_archived_clients_invitation_cannot_be_accepted(): void
    {
        $token = $this->invite();
        $this->client->update(['status' => ClientStatus::Archived]);

        $this->portal('POST', 'invitations/preview', ['token' => $token])->assertJsonPath('data.status', 'unavailable');
        $this->portal('POST', 'invitations/accept', ['token' => $token])->assertUnprocessable();
    }
}
