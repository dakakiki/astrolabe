<?php

namespace Tests\Feature\Portal;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PortalLoginToken;
use App\Models\PortalUser;
use App\Models\User;
use App\Notifications\PortalSignInLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * Signing in to the portal with an emailed link or code (docs/spec/12,
 * "Prijava"; acceptance criterion 2): single use, 15 minutes, five wrong codes
 * end it, and the answer never tells whether an address has an account.
 */
class PortalSignInTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private PortalUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $astrologer = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $client = Client::factory()->inWorkspace($astrologer->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($client);
    }

    /**
     * Asks for a sign-in email and returns its link token and code.
     *
     * @return array{0: string, 1: string}
     */
    private function askForSignIn(string $email = 'ana@example.com'): array
    {
        $this->portal('POST', 'sign-in', ['email' => $email])->assertStatus(202);

        $sent = null;
        Notification::assertSentTo($this->user, PortalSignInLink::class, function (PortalSignInLink $mail) use (&$sent) {
            $message = $mail->toMail($this->user);
            preg_match('/(\d{6})/', implode(' ', array_merge($message->introLines, $message->outroLines)), $code);
            $sent = [substr($message->actionUrl, strpos($message->actionUrl, '#') + 1), $code[1]];

            return str_starts_with($message->actionUrl, 'http://portal.astrolabe.test/sign-in/link#');
        });

        return $sent;
    }

    public function test_the_session_endpoint_answers_a_guest(): void
    {
        $this->portal('GET', 'session')
            ->assertOk()
            ->assertJsonPath('data.state', 'guest')
            ->assertJsonPath('data.user', null)
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_asking_gives_the_same_answer_for_any_address(): void
    {
        $known = $this->portal('POST', 'sign-in', ['email' => 'ANA@example.com'])->assertStatus(202)->json('message');
        $unknown = $this->portal('POST', 'sign-in', ['email' => 'nobody@example.com'])->assertStatus(202)->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($this->user, PortalSignInLink::class);
        Notification::assertCount(1);
    }

    public function test_an_account_whose_practice_does_not_open_gets_no_email(): void
    {
        DB::table('portal_access')->update(['status' => 'revoked']);

        $this->portal('POST', 'sign-in', ['email' => 'ana@example.com'])->assertStatus(202);

        Notification::assertNothingSent();
        $this->assertSame(0, PortalLoginToken::query()->count());
    }

    public function test_the_emailed_link_signs_in_once(): void
    {
        [$token] = $this->askForSignIn();

        $this->portal('POST', 'sign-in/link', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.user.email', 'ana@example.com');
        $this->portal('GET', 'home')->assertOk();

        $this->forgetPortalCookie();
        $this->portal('POST', 'sign-in/link', ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->portal('GET', 'home')->assertUnauthorized();

        $this->assertNotNull($this->user->fresh()->last_signed_in_at);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalSignedIn)->where('portal_user_id', $this->user->id)->count());
    }

    public function test_the_code_signs_in_on_another_device_and_uses_up_the_link(): void
    {
        [$token, $code] = $this->askForSignIn();

        $this->portal('POST', 'sign-in/code', ['email' => 'Ana@Example.com', 'code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertOk()
            ->assertJsonPath('data.state', 'ready');

        $this->forgetPortalCookie();
        $this->portal('POST', 'sign-in/link', ['token' => $token])->assertUnprocessable();
    }

    public function test_using_the_link_uses_up_the_code(): void
    {
        [$token, $code] = $this->askForSignIn();
        $this->portal('POST', 'sign-in/link', ['token' => $token])->assertOk();

        $this->forgetPortalCookie();
        $this->portal('POST', 'sign-in/code', ['email' => 'ana@example.com', 'code' => $code])->assertUnprocessable();
    }

    public function test_five_wrong_codes_end_the_request(): void
    {
        [, $code] = $this->askForSignIn();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->portal('POST', 'sign-in/code', ['email' => 'ana@example.com', 'code' => $wrong])->assertUnprocessable();
        }

        $this->portal('POST', 'sign-in/code', ['email' => 'ana@example.com', 'code' => $code])->assertUnprocessable();
        $this->portal('GET', 'home')->assertUnauthorized();
        $this->assertSame(6, AuditLog::query()->where('event', AuditEvent::PortalSignInFailed)->count());
    }

    public function test_a_wrong_code_and_an_unknown_address_answer_alike(): void
    {
        $this->askForSignIn();

        $wrong = $this->portal('POST', 'sign-in/code', ['email' => 'ana@example.com', 'code' => '123456'])->json('errors.code.0');
        $unknown = $this->portal('POST', 'sign-in/code', ['email' => 'nobody@example.com', 'code' => '123456'])->json('errors.code.0');

        $this->assertSame($wrong, $unknown);
    }

    public function test_link_and_code_work_for_fifteen_minutes(): void
    {
        [$token, $code] = $this->askForSignIn();

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $this->portal('POST', 'sign-in/code', ['email' => 'ana@example.com', 'code' => $code])->assertUnprocessable();
        $this->portal('POST', 'sign-in/link', ['token' => $token])->assertUnprocessable();
    }

    public function test_a_new_request_replaces_the_earlier_one(): void
    {
        [$first] = $this->askForSignIn();
        Notification::fake();
        [$second] = $this->askForSignIn();

        $this->portal('POST', 'sign-in/link', ['token' => $first])->assertUnprocessable();
        $this->portal('POST', 'sign-in/link', ['token' => $second])->assertOk();
    }

    public function test_only_hashes_are_stored(): void
    {
        [$token, $code] = $this->askForSignIn();

        $row = (array) DB::table('portal_login_tokens')->first();
        $this->assertNotContains($token, $row);
        $this->assertNotContains($code, $row);
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
    }

    public function test_asking_is_limited_per_address(): void
    {
        RateLimiter::clear('portal-sign-in-email:'.sha1('ana@example.com'));

        for ($i = 0; $i < 3; $i++) {
            $this->portal('POST', 'sign-in', ['email' => 'ana@example.com'])->assertStatus(202);
        }

        $this->portal('POST', 'sign-in', ['email' => 'ana@example.com'])->assertStatus(429);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->signInToPortal($this->user);

        $this->portal('POST', 'sign-out')->assertNoContent();
        $this->portal('GET', 'home')->assertUnauthorized();
        $this->portal('GET', 'session')->assertJsonPath('data.state', 'guest');
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalSignedOut)->count());
    }

    public function test_the_session_lives_in_its_own_table_and_cookie(): void
    {
        $this->signInToPortal($this->user);

        $this->assertNotNull($this->portalCookie());
        $this->assertSame(1, DB::table('portal_sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_security_lists_the_sessions_and_signs_out_everywhere(): void
    {
        $this->signInToPortal($this->user);
        $phone = $this->portalCookie();

        $this->forgetPortalCookie();
        $this->signInToPortal($this->user);

        $this->portal('GET', 'sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.current', true)
            ->assertJsonMissingPath('data.0.id');

        $this->portal('DELETE', 'sessions')->assertNoContent();
        $this->portal('GET', 'home')->assertUnauthorized();

        // The other device is signed out as well.
        $this->usePortalCookie($phone);
        $this->portal('GET', 'home')->assertUnauthorized();
        $this->assertSame(0, DB::table('portal_sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalSessionsRevoked)->count());
    }
}
