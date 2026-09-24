<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_sign_in_and_see_who_they_are(): void
    {
        $user = User::factory()->withWorkspace(['name' => 'Vega Astrology'])->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->assertAuthenticatedAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonPath('data.workspace.name', 'Vega Astrology')
            ->assertJsonPath('data.workspace.role', 'owner');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $user = User::factory()->withWorkspace()->create();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();
    }

    public function test_users_can_sign_out(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_guests_get_401_from_the_api(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/workspace')->assertUnauthorized();
    }
}
