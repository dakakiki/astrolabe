<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditEvent;
use App\Enums\HouseSystem;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Enums\ZodiacMode;
use App\Models\AuditLog;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function invite(string $email = 'mila@example.com', int $days = 14): string
    {
        [, $token] = RegistrationInvitation::issue($email, $days);

        return $token;
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', $overrides + [
            'name' => 'Mila Vega',
            'email' => 'mila@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'workspace_name' => 'Vega Astrology',
            'timezone' => 'Europe/Belgrade',
            'locale' => 'en',
            // The Terms and the DPA accepted, the privacy policy seen (Phase 8c).
            'accept_terms' => true,
            'legal' => LegalDocuments::currentVersions(),
        ]);
    }

    public function test_registration_creates_the_user_and_an_owned_workspace(): void
    {
        Notification::fake();

        $this->register(['invitation' => $this->invite()])->assertCreated();

        $user = User::where('email', 'mila@example.com')->firstOrFail();
        $workspace = $user->currentWorkspace;

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Europe/Belgrade', $user->timezone);
        $this->assertSame('Vega Astrology', $workspace->name);
        $this->assertSame('Europe/Belgrade', $workspace->timezone);
        $this->assertSame(HouseSystem::Placidus, $workspace->default_house_system);
        $this->assertSame(ZodiacMode::Tropical, $workspace->default_zodiac_mode);
        $this->assertSame(WorkspaceRole::Owner, $user->roleIn($workspace));
        $this->assertSame(MembershipStatus::Active, $user->workspaces()->first()->membership->status);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_a_missing_practice_name_gets_a_default(): void
    {
        $this->register(['workspace_name' => null, 'invitation' => $this->invite()])->assertCreated();

        $this->assertSame("Mila Vega's practice", User::firstOrFail()->currentWorkspace->name);
    }

    public function test_the_verification_link_opens_the_spa(): void
    {
        Notification::fake();

        $this->register(['invitation' => $this->invite()])->assertCreated();
        $user = User::firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, url('/verify-email/'.$user->id.'/'))
                && str_contains($url, 'signature=');
        });
    }

    public function test_registration_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->register([
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'timezone' => 'Mars/Olympus_Mons',
            'locale' => 'xx',
            'invitation' => $this->invite('taken@example.com'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'timezone', 'locale']);

        $this->assertGuest();
    }

    public function test_the_closed_beta_needs_an_invitation(): void
    {
        $this->register()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation' => 'closed beta']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_invitation_is_used_up_and_recorded(): void
    {
        $token = $this->invite('Mila@Example.com');

        // Addresses compare without regard to case.
        $this->register(['invitation' => $token, 'email' => 'mila@EXAMPLE.com'])->assertCreated();

        $user = User::firstOrFail();
        $invitation = RegistrationInvitation::firstOrFail();

        $this->assertSame(RegistrationInvitation::USED, $invitation->status());
        $this->assertSame($user->id, $invitation->user_id);
        $this->assertTrue(AuditLog::where('event', AuditEvent::InvitationAccepted)->where('user_id', $user->id)->exists());
        $this->assertTrue(AuditLog::where('event', AuditEvent::Registered)->where('user_id', $user->id)->exists());

        // One link makes one account.
        $this->post('/api/v1/auth/logout');
        $this->register(['invitation' => $token, 'email' => 'mila.second@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invitation');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_invitation_that_does_not_work_is_refused_with_the_reason(): void
    {
        $this->register(['invitation' => 'not-a-real-token'])
            ->assertJsonValidationErrors(['invitation' => 'not valid']);

        $expired = $this->invite(days: 1);
        $this->travel(2)->days();
        $this->register(['invitation' => $expired])->assertJsonValidationErrors(['invitation' => 'expired']);
        $this->travelBack();

        $revoked = $this->invite();
        $this->invite(); // a new invitation replaces the earlier one
        $this->register(['invitation' => $revoked])->assertJsonValidationErrors(['invitation' => 'no longer valid']);

        $this->register(['invitation' => $this->invite('someone.else@example.com')])
            ->assertJsonValidationErrors(['invitation' => 'different email']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_open_registration_needs_no_invitation(): void
    {
        config(['astrolabe.registration.mode' => 'open']);

        $this->register()->assertCreated();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_registration_page_learns_the_mode_and_the_invitation(): void
    {
        $this->getJson('/api/v1/auth/registration')
            ->assertOk()
            ->assertJsonPath('data.mode', 'invite')
            ->assertJsonPath('data.invitation', null);

        $token = $this->invite();
        $this->getJson('/api/v1/auth/registration?invitation='.$token)
            ->assertJsonPath('data.invitation.status', 'valid')
            ->assertJsonPath('data.invitation.email', 'mila@example.com');

        // Only a working link tells whom it is for.
        $this->getJson('/api/v1/auth/registration?invitation=nope')
            ->assertJsonPath('data.invitation', ['status' => 'invalid']);

        $this->register(['invitation' => $token])->assertCreated();
        $this->post('/api/v1/auth/logout');

        $this->getJson('/api/v1/auth/registration?invitation='.$token)
            ->assertJsonPath('data.invitation', ['status' => 'used']);

        config(['astrolabe.registration.mode' => 'open']);
        $this->getJson('/api/v1/auth/registration')->assertJsonPath('data.mode', 'open');
    }
}
