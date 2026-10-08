<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Notifications\RegistrationInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invitation_is_emailed_with_a_link_to_the_registration_page(): void
    {
        Notification::fake();

        $this->artisan('invitations:send', ['emails' => ['Ana@Example.com'], '--days' => 7, '--note' => 'from the workshop'])
            ->expectsOutputToContain('ana@example.com: invitation sent')
            ->assertSuccessful();

        $invitation = RegistrationInvitation::firstOrFail();
        $this->assertSame('ana@example.com', $invitation->email);
        $this->assertSame('from the workshop', $invitation->note);
        $this->assertSame(RegistrationInvitation::VALID, $invitation->status());
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $invitation->expires_at->getTimestamp(), 5);

        Notification::assertSentOnDemand(
            RegistrationInvite::class,
            function (RegistrationInvite $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation) {
                $url = $notification->toMail($notifiable)->actionUrl;
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                // The link carries the token; the database keeps only its hash.
                return $notifiable->routes['mail'] === 'ana@example.com'
                    && str_starts_with($url, url('/register?invitation='))
                    && RegistrationInvitation::hash($query['invitation']) === $invitation->token_hash;
            },
        );

        $this->assertTrue(AuditLog::where('event', AuditEvent::InvitationSent)->where('subject_id', $invitation->id)->exists());
    }

    public function test_a_new_invitation_replaces_the_open_one(): void
    {
        Notification::fake();

        $this->artisan('invitations:send', ['emails' => ['ana@example.com']])->assertSuccessful();
        $this->artisan('invitations:send', ['emails' => ['ana@example.com']])->assertSuccessful();

        $this->assertSame(
            [RegistrationInvitation::REVOKED, RegistrationInvitation::VALID],
            RegistrationInvitation::orderBy('id')->get()->map->status()->all(),
        );
    }

    public function test_existing_accounts_and_bad_addresses_get_nothing(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'mila@example.com']);

        $this->artisan('invitations:send', ['emails' => ['mila@example.com', 'not-an-address']])
            ->expectsOutputToContain('already has an account')
            ->expectsOutputToContain('not an email address')
            ->assertFailed();

        $this->artisan('invitations:send', ['emails' => ['ana@example.com'], '--days' => 0])->assertExitCode(2);

        $this->assertDatabaseCount('registration_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_invitations_can_be_listed_and_revoked(): void
    {
        Notification::fake();
        $this->artisan('invitations:send', ['emails' => ['ana@example.com', 'luka@example.com']])->assertSuccessful();

        $this->artisan('invitations:revoke', ['email' => 'ANA@example.com'])
            ->expectsOutput('Invitation revoked.')
            ->assertSuccessful();

        $this->assertSame(RegistrationInvitation::REVOKED, RegistrationInvitation::where('email', 'ana@example.com')->first()->status());
        $this->assertTrue(AuditLog::where('event', AuditEvent::InvitationRevoked)->exists());

        $this->artisan('invitations:list')
            ->expectsOutputToContain('luka@example.com')
            ->assertSuccessful();

        $this->artisan('invitations:revoke', ['email' => 'ana@example.com'])
            ->expectsOutput('No open invitation for that address.')
            ->assertSuccessful();
    }
}
