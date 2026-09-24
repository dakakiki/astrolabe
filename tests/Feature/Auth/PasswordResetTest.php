<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_pointing_at_the_spa_is_sent(): void
    {
        Notification::fake();
        $user = User::factory()->withWorkspace()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, url('/reset-password/'.$notification->token))
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();
        $user = User::factory()->withWorkspace()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $repeated = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame($known->json(), $repeated->json());
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_the_password_can_be_reset_with_the_token(): void
    {
        Notification::fake();
        $user = User::factory()->withWorkspace()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->postJson('/api/v1/auth/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertOk();

            return true;
        });

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
