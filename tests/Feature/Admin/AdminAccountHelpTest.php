<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Account help from the admin (Phase 8c): each action needs the operator's
 * password again and a reason, is recorded with that reason, and is told to
 * the astrologer. Admin accounts are not touched from here.
 */
class AdminAccountHelpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $astrologer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->astrologer = User::factory()->withWorkspace()->create([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['a', 'b'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** A request from the SPA: it has a session, as password confirmation needs. */
    private function fromTheSpa(): static
    {
        config(['sanctum.stateful' => [parse_url(config('app.url'), PHP_URL_HOST)]]);

        return $this->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')]);
    }

    /** As the admin, with the password confirmed a moment ago. */
    private function asAdmin(): static
    {
        return $this->fromTheSpa()->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_every_action_asks_for_the_password_again(): void
    {
        $this->fromTheSpa()->actingAs($this->admin)
            ->postJson("/api/v1/admin/astrologers/{$this->astrologer->id}/two-factor-reset", ['reason' => 'Lost phone'])
            ->assertStatus(423);

        $this->assertTrue($this->astrologer->refresh()->hasTwoFactorEnabled());
    }

    public function test_turning_off_two_factor_sign_in_needs_a_reason_and_tells_the_astrologer(): void
    {
        $url = "/api/v1/admin/astrologers/{$this->astrologer->id}/two-factor-reset";

        $this->asAdmin()->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->asAdmin()->postJson($url, ['reason' => 'Lost phone and recovery codes, called on 9 Oct'])
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);

        $this->assertFalse($this->astrologer->refresh()->hasTwoFactorEnabled());
        $this->assertNull($this->astrologer->two_factor_recovery_codes);

        $entry = AuditLog::query()->where('event', AuditEvent::AdminTwoFactorReset)->sole();
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame($this->astrologer->id, $entry->subject_id);
        $this->assertSame('Lost phone and recovery codes, called on 9 Oct', $entry->properties['reason']);

        Notification::assertSentTo($this->astrologer, AccountNotice::class, fn (AccountNotice $notice) => $notice->kind === AccountNotice::TWO_FACTOR_RESET);

        $this->asAdmin()->postJson($url, ['reason' => 'again'])->assertStatus(409);
    }

    public function test_suspending_ends_sessions_and_stops_signing_in_until_restored(): void
    {
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->astrologer->id, 'payload' => '', 'last_activity' => time()]);
        $this->astrologer->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        $this->asAdmin()->postJson("/api/v1/admin/astrologers/{$this->astrologer->id}/suspension", ['reason' => 'Unpaid beta agreement'])
            ->assertOk()
            ->assertJsonPath('data.suspension_reason', 'Unpaid beta agreement');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->astrologer->id)->count());
        Notification::assertSentTo($this->astrologer, AccountNotice::class, fn (AccountNotice $notice) => $notice->kind === AccountNotice::SUSPENDED);

        // Signing in with the right password is refused and recorded; the wrong one says nothing more.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $this->astrologer->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', __('admin.suspended'));
        $this->postJson('/api/v1/auth/login', ['email' => $this->astrologer->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonMissing(['message' => __('admin.suspended')]);
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::LoginFailed)->where('user_id', $this->astrologer->id)->get()
            ->contains(fn (AuditLog $entry) => ($entry->properties['suspended'] ?? false) === true));

        // A session that still existed somewhere is refused too.
        $this->actingAs($this->astrologer->refresh())->getJson('/api/v1/clients')->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->asAdmin()->deleteJson("/api/v1/admin/astrologers/{$this->astrologer->id}/suspension")->assertOk()->assertJsonPath('data.suspended_at', null);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $this->astrologer->email, 'password' => 'password'])->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::AccountSuspended)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::AccountRestored)->count());
        Notification::assertSentTo($this->astrologer, AccountNotice::class, fn (AccountNotice $notice) => $notice->kind === AccountNotice::RESTORED);
    }

    public function test_the_confirmation_email_can_be_sent_again(): void
    {
        $new = User::factory()->withWorkspace()->unverified()->create();

        $this->asAdmin()->postJson("/api/v1/admin/astrologers/{$new->id}/verification")->assertOk();
        $this->asAdmin()->postJson("/api/v1/admin/astrologers/{$this->astrologer->id}/verification")->assertStatus(409);

        Notification::assertSentTo($new, VerifyEmail::class);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::AdminVerificationResent)->count());
    }

    public function test_admin_accounts_are_not_touched_from_the_admin(): void
    {
        $other = User::factory()->admin()->create();

        $this->asAdmin()->postJson("/api/v1/admin/astrologers/{$other->id}/suspension", ['reason' => 'x'])->assertForbidden();
        $this->asAdmin()->postJson("/api/v1/admin/astrologers/{$other->id}/two-factor-reset", ['reason' => 'x'])->assertForbidden();

        $this->assertFalse($other->refresh()->isSuspended());
        $this->assertTrue($other->hasTwoFactorEnabled());
    }

    public function test_an_astrologer_cannot_do_any_of_it(): void
    {
        $colleague = User::factory()->withWorkspace()->create();

        $this->fromTheSpa()->actingAs($colleague)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/admin/astrologers/{$this->astrologer->id}/suspension", ['reason' => 'x'])
            ->assertForbidden();

        $this->assertFalse($this->astrologer->refresh()->isSuspended());
    }
}
