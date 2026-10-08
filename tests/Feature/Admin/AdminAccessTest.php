<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Notifications\AdminWelcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Who reaches the operator's admin (Phase 8c): only the admin account, only
 * with two-factor sign-in, never a practice — and astrologers never the admin.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_astrologers_and_guests_are_kept_out(): void
    {
        $astrologer = User::factory()->withWorkspace()->create();

        $this->getJson('/api/v1/admin/astrologers')->assertUnauthorized();
        $this->actingAs($astrologer)->getJson('/api/v1/admin/astrologers')->assertForbidden();
        $this->actingAs($astrologer)->getJson('/api/v1/admin/system')->assertForbidden();
    }

    public function test_the_admin_needs_two_factor_sign_in_first(): void
    {
        $admin = User::factory()->admin(twoFactor: false)->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/astrologers')
            ->assertForbidden()
            ->assertJsonPath('code', 'admin_two_factor_required');

        // Turning it on happens through the account's own settings, which need no practice.
        $this->actingAs($admin)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_admin', true)
            ->assertJsonPath('data.user.two_factor_enabled', false)
            ->assertJsonPath('data.workspace', null);
    }

    public function test_the_admin_never_reaches_a_practice(): void
    {
        $admin = User::factory()->admin()->create();
        $astrologer = User::factory()->withWorkspace()->create();
        $client = Client::factory()->inWorkspace($astrologer->current_workspace_id)->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/astrologers')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/clients')->assertForbidden();
        $this->actingAs($admin)->getJson("/api/v1/clients/{$client->id}")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertForbidden();
        $this->assertSame([], $admin->workspaces()->pluck('workspaces.id')->all());
    }

    public function test_the_admin_session_ends_after_thirty_quiet_minutes(): void
    {
        $admin = User::factory()->admin()->create();
        $stateful = ['Referer' => config('app.url'), 'Origin' => config('app.url')];
        config(['sanctum.stateful' => [parse_url(config('app.url'), PHP_URL_HOST)]]);

        $this->actingAs($admin)->withHeaders($stateful)->getJson('/api/v1/admin/system')->assertOk();
        $this->travel(29)->minutes();
        $this->withHeaders($stateful)->getJson('/api/v1/admin/system')->assertOk();
        $this->travel(31)->minutes();
        $this->withHeaders($stateful)->getJson('/api/v1/admin/system')->assertUnauthorized();
    }

    public function test_admin_create_makes_a_separate_account_with_a_link_to_set_its_password(): void
    {
        Notification::fake();
        User::factory()->withWorkspace()->create(['email' => 'mila@example.com']);

        $this->artisan('admin:create', ['email' => 'mila@example.com'])->assertFailed();
        $this->artisan('admin:create', ['email' => 'Operator@Example.com', '--name' => 'Davor'])
            ->expectsOutputToContain('/reset-password/')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'operator@example.com')->sole();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertFalse($admin->hasTwoFactorEnabled());
        $this->assertSame([], $admin->workspaces()->pluck('workspaces.id')->all());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::AdminCreated)->where('subject_id', $admin->id)->count());

        Notification::assertSentTo($admin, AdminWelcome::class, function (AdminWelcome $notification) use ($admin) {
            parse_str((string) parse_url($notification->link, PHP_URL_QUERY), $query);
            $token = basename((string) parse_url($notification->link, PHP_URL_PATH));

            return $query['email'] === $admin->email && Password::broker()->tokenExists($admin, $token);
        });

        $this->artisan('admin:list')->expectsOutputToContain('operator@example.com')->assertSuccessful();
    }

    public function test_an_admin_cannot_be_registered_or_be_made_from_a_request(): void
    {
        $astrologer = User::factory()->withWorkspace()->create();

        $this->actingAs($astrologer)->putJson('/api/v1/auth/user/profile-information', [
            'name' => $astrologer->name,
            'email' => $astrologer->email,
            'is_admin' => true,
        ]);

        $this->assertFalse($astrologer->refresh()->isAdmin());
    }
}
