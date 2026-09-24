<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_and_regional_preferences_can_be_updated_separately(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/user/profile-information', ['name' => 'Mila V.'])->assertOk();
        $this->actingAs($user)->putJson('/api/v1/auth/user/profile-information', [
            'timezone' => 'America/New_York',
            'locale' => 'en',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('Mila V.', $user->name);
        $this->assertSame('America/New_York', $user->timezone);
    }

    public function test_changing_the_email_requires_verifying_it_again(): void
    {
        Notification::fake();
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/user/profile-information', ['email' => 'new@example.com'])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_preferences_are_validated(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/user/profile-information', [
            'timezone' => 'Nowhere/Special',
            'locale' => 'tlh',
            'name' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['timezone', 'locale', 'name']);
    }

    public function test_the_password_can_be_changed_with_the_current_one(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'wrong',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_other_sessions_can_be_signed_out_with_the_password(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $stranger = User::factory()->withWorkspace()->create();

        $sessions = [
            ['id' => 'other-device', 'user_id' => $user->id],
            ['id' => 'strangers-device', 'user_id' => $stranger->id],
        ];
        foreach ($sessions as $session) {
            DB::table('sessions')->insert($session + ['payload' => '', 'last_activity' => time()]);
        }

        $this->actingAs($user)->getJson('/api/v1/auth/other-sessions')->assertJsonPath('data.count', 1);

        $this->actingAs($user)->deleteJson('/api/v1/auth/other-sessions', ['password' => 'wrong'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('sessions', ['id' => 'other-device']);

        $this->actingAs($user)->deleteJson('/api/v1/auth/other-sessions', ['password' => 'password'])
            ->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'strangers-device']);
    }
}
