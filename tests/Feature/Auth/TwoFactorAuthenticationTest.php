<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The app's code. Fortify takes each code once, so a second sign-in within
     * the same 30 seconds uses the next one (still inside the allowed window).
     */
    private function code(User $user, int $ahead = 0): string
    {
        $google2fa = app(Google2FA::class);

        return $google2fa->oathTotp(decrypt($user->fresh()->two_factor_secret), $google2fa->getTimestamp() + $ahead);
    }

    /** Turns two-factor on the way the settings page does: password, secret, one code. */
    private function enable(User $user): User
    {
        $this->actingAs($user)->postJson('/api/v1/auth/user/confirm-password', ['password' => 'password'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => $this->code($user)])
            ->assertOk();

        return $user->fresh();
    }

    public function test_turning_it_on_needs_the_password_and_a_code_from_the_app(): void
    {
        $user = User::factory()->withWorkspace()->create();

        // Without a recent password confirmation the settings refuse.
        $this->actingAs($user)->postJson('/api/v1/auth/user/two-factor-authentication')->assertStatus(423);

        $this->actingAs($user)->postJson('/api/v1/auth/user/confirm-password', ['password' => 'password'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();

        $this->actingAs($user)->getJson('/api/v1/auth/user/two-factor-qr-code')
            ->assertOk()
            ->assertJsonStructure(['svg', 'url']);
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.user.two_factor_enabled', false);

        $this->actingAs($user)->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => $this->code($user)])
            ->assertOk();

        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.user.two_factor_enabled', true);
        $this->actingAs($user)->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk()->assertJsonCount(8);
        $this->assertTrue(AuditLog::where('event', AuditEvent::TwoFactorEnabled)->where('user_id', $user->id)->exists());

        // The secret never leaves in the user's data.
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());
    }

    public function test_signing_in_then_asks_for_the_code(): void
    {
        $user = $this->enable(User::factory()->withWorkspace()->create());
        $this->post('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('two_factor', true);
        $this->assertGuest();

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('event', AuditEvent::TwoFactorFailed)->where('user_id', $user->id)->exists());

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->code($user, ahead: 1)])->assertNoContent();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->enable(User::factory()->withWorkspace()->create());
        $recovery = $user->recoveryCodes()[0];
        $this->post('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $recovery])->assertNoContent();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(AuditLog::where('event', AuditEvent::RecoveryCodeUsed)->where('user_id', $user->id)->exists());

        $this->post('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $recovery])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_guessing_the_code_is_throttled(): void
    {
        $user = $this->enable(User::factory()->withWorkspace()->create());
        $this->post('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->code($user)])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_it_can_be_turned_off_and_the_recovery_codes_renewed(): void
    {
        $user = $this->enable(User::factory()->withWorkspace()->create());
        $old = $user->recoveryCodes();

        $this->actingAs($user)->postJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk();
        $this->assertNotSame($old, $user->fresh()->recoveryCodes());
        $this->assertTrue(AuditLog::where('event', AuditEvent::RecoveryCodesRegenerated)->exists());

        $this->actingAs($user)->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertTrue(AuditLog::where('event', AuditEvent::TwoFactorDisabled)->exists());
    }
}
