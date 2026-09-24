<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function signedVerificationUrl(User $user, ?string $email = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($email ?? $user->email),
        ]);
    }

    public function test_unverified_users_can_load_their_session_but_not_the_workspace(): void
    {
        $user = User::factory()->unverified()->withWorkspace()->create();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', false);

        $this->actingAs($user)->getJson('/api/v1/workspace')->assertForbidden();
    }

    public function test_the_signed_link_verifies_the_email_address(): void
    {
        $user = User::factory()->unverified()->withWorkspace()->create();

        $this->actingAs($user)->getJson($this->signedVerificationUrl($user))->assertNoContent();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_link_for_another_address_is_refused(): void
    {
        $user = User::factory()->unverified()->withWorkspace()->create();

        $this->actingAs($user)->getJson($this->signedVerificationUrl($user, 'someone@else.com'))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $user = User::factory()->unverified()->withWorkspace()->create();

        $this->actingAs($user)->getJson($this->signedVerificationUrl($user).'x')->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_verification_email_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->withWorkspace()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
