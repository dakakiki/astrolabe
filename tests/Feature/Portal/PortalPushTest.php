<?php

namespace Tests\Feature\Portal;

use App\Actions\Clients\DeleteClient;
use App\Enums\AuditEvent;
use App\Enums\PortalNotificationChannel;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PortalPushSubscription;
use App\Models\PortalUser;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DataExport\PracticeExport;
use App\Support\Retention\Retention;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;
use ZipArchive;

/**
 * Web push in the client PWA (Phase 9c, docs/spec/12, "Web push"): switched on
 * per device after signing in, tied to the portal session it was made in, never
 * handing the browser's endpoint or keys back, limited per account, and gone
 * with the session, "Sign out everywhere", the last active link, the account
 * or `data:prune`. Not a practice's data: not in the practice export.
 */
class PortalPushTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    /** What this browser's push service gave it (Chrome's, on the allowlist). */
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/this-browser-c2VjcmV0';

    private User $vega;

    private Client $ana;

    private PortalUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->vega = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->ana = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($this->ana);
    }

    /**
     * What the portal sends after the browser's PushManager.subscribe().
     *
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function subscription(string $endpoint = self::ENDPOINT, string $p256dh = 'BKeyOfThisBrowser'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => 'AuthOfThisBrowser']];
    }

    /** A portal session that ran past the lifetime and is still in the table (data:prune not run yet). */
    private function expiredSession(PortalUser $user, string $id = 'expired-session'): string
    {
        DB::table('portal_sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->subMinutes(config('portal.session.lifetime') + 1)->getTimestamp(),
        ]);

        return $id;
    }

    private function audited(AuditEvent $event): int
    {
        return AuditLog::query()->where('event', $event)->count();
    }

    public function test_without_vapid_keys_push_is_not_offered(): void
    {
        // As phpunit.xml has it, whatever keys a developer's .env holds.
        config(['portal.push.public_key' => null, 'portal.push.private_key' => null]);
        $this->signInToPortal($this->user);

        $this->portal('GET', 'push')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.public_key', null)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonCount(0, 'data.devices');

        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertStatus(409)
            ->assertJsonPath('code', 'push_unavailable');

        $this->assertSame(0, PortalPushSubscription::query()->count());
        $this->assertSame('email', $this->user->fresh()->notification_channel->value);
    }

    public function test_the_state_lists_only_this_accounts_live_devices_and_marks_this_one(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);
        $session = $this->latestPortalSessionId($this->user);

        $here = $this->pushDevice($this->user, $session);
        $phone = $this->pushDevice($this->user);
        $ended = $this->pushDevice($this->user, 'a-session-that-ended');
        $expired = $this->pushDevice($this->user, $this->expiredSession($this->user));
        $theirs = $this->pushDevice(PortalUser::factory()->create());

        $response = $this->portal('GET', 'push')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.public_key', 'BTestPublicKeyForThePortalPushTestsOnly')
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonCount(2, 'data.devices');

        $devices = collect($response->json('data.devices'))->keyBy('hash');
        $this->assertEqualsCanonicalizing([$here->endpoint_hash, $phone->endpoint_hash], $devices->keys()->all());
        $this->assertTrue($devices[$here->endpoint_hash]['current']);
        $this->assertFalse($devices[$phone->endpoint_hash]['current']);
        $this->assertSame(['hash', 'current', 'user_agent', 'created_at', 'last_used_at'], array_keys($devices[$here->endpoint_hash]));

        // The endpoint works like a key: neither it nor the browser's keys nor a session id come back.
        $body = json_encode($response->json(), JSON_UNESCAPED_SLASHES);
        foreach ([$here, $phone, $ended, $expired, $theirs] as $device) {
            $this->assertStringNotContainsString($device->endpoint, $body);
            $this->assertStringNotContainsString($device->portal_session_id, $body);
        }
        $this->assertStringNotContainsString('BPublicKeyOfTheBrowser', $body);
        $this->assertStringNotContainsString('AuthSecretOfTheBrowser', $body);
        $this->assertStringNotContainsString('"endpoint"', $body);
        $this->assertStringNotContainsString('auth_token', $body);
        $this->assertStringNotContainsString('session', $body);
    }

    public function test_switching_push_on_stores_this_browser_with_this_session(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);

        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertCreated()
            ->assertJsonPath('data.available', true)
            ->assertJsonCount(1, 'data.devices')
            ->assertJsonPath('data.devices.0.hash', hash('sha256', self::ENDPOINT))
            ->assertJsonPath('data.devices.0.current', true)
            ->assertJsonPath('data.devices.0.last_used_at', null);

        $row = PortalPushSubscription::query()->sole();
        $this->assertSame($this->user->id, $row->portal_user_id);
        $this->assertSame($this->latestPortalSessionId($this->user), $row->portal_session_id);
        $this->assertSame(self::ENDPOINT, $row->endpoint);
        $this->assertSame('BKeyOfThisBrowser', $row->public_key);
        $this->assertSame('AuthOfThisBrowser', $row->auth_token);
        $this->assertSame('aes128gcm', $row->content_encoding);

        // The browser renews its subscription with new keys: the same row, not a second device.
        $this->portal('POST', 'push/subscriptions', $this->subscription(p256dh: 'BNewKeyOfThisBrowser') + ['content_encoding' => 'aesgcm'])
            ->assertOk()
            ->assertJsonCount(1, 'data.devices');

        $this->assertSame(1, PortalPushSubscription::query()->count());
        $this->assertSame('BNewKeyOfThisBrowser', $row->fresh()->public_key);
        $this->assertSame('aesgcm', $row->fresh()->content_encoding);

        // Audited once, for switching on, and without the endpoint.
        $entry = AuditLog::query()->where('event', AuditEvent::PortalPushEnabled)->sole();
        $this->assertSame($this->user->id, $entry->portal_user_id);
        $this->assertStringNotContainsString('fcm.googleapis.com', (string) json_encode($entry->properties));
        $this->assertStringNotContainsString(hash('sha256', self::ENDPOINT), (string) json_encode($entry->properties));
    }

    public function test_the_subscription_must_be_complete(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);

        $this->portal('POST', 'push/subscriptions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys']);
        $this->portal('POST', 'push/subscriptions', ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'BKey']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keys.auth');
        $this->portal('POST', 'push/subscriptions', $this->subscription() + ['content_encoding' => 'gzip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_encoding');
        $this->portal('POST', 'push/subscriptions', $this->subscription(self::ENDPOINT.str_repeat('x', 2048)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertSame(0, PortalPushSubscription::query()->count());
        $this->assertSame(0, $this->audited(AuditEvent::PortalPushEnabled));
    }

    public function test_only_the_known_push_services_are_accepted(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);

        foreach ([
            'http://fcm.googleapis.com/fcm/send/abc',
            'https://push.example.com/fcm/send/abc',
            'https://fcm.googleapis.com.example.com/fcm/send/abc',
            'https://someone:secret@fcm.googleapis.com/fcm/send/abc',
            'https://fcm.googleapis.com:8443/fcm/send/abc',
        ] as $endpoint) {
            $this->portal('POST', 'push/subscriptions', $this->subscription($endpoint))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['endpoint' => __('portal.errors.push_unsupported')]);
        }

        $this->assertSame(0, PortalPushSubscription::query()->count());

        // Safari's and Edge's push services (a subdomain of the listed suffix).
        $this->portal('POST', 'push/subscriptions', $this->subscription('https://web.push.apple.com/QGhlbGxv'))->assertCreated();
        $this->portal('POST', 'push/subscriptions', $this->subscription('https://wns2-par02p.notify.windows.com/w/?token=abc'))->assertCreated();

        $this->assertSame(2, PortalPushSubscription::query()->count());
    }

    public function test_a_browser_that_signs_in_to_another_account_moves_to_it(): void
    {
        $this->fakePush();
        $bob = PortalUser::factory()->create(['email' => 'bob@example.com']);
        $this->pushDevice($bob, null, self::ENDPOINT);

        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();

        $row = PortalPushSubscription::query()->sole();
        $this->assertSame($this->user->id, $row->portal_user_id);
        $this->assertSame('BKeyOfThisBrowser', $row->public_key);
        $this->assertSame(0, $bob->pushSubscriptions()->count());
        $this->assertSame(1, $this->audited(AuditEvent::PortalPushEnabled));
    }

    public function test_the_first_device_adds_push_to_email_but_a_chosen_channel_stays(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);

        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertCreated()
            ->assertJsonPath('data.channel', 'both');
        $this->assertSame(PortalNotificationChannel::Both, $this->user->fresh()->notification_channel);

        // Push only, chosen in Profile: another device leaves it as it is.
        $this->portal('PUT', 'profile', ['notification_channel' => 'push'])->assertOk();
        $this->portal('POST', 'push/subscriptions', $this->subscription('https://fcm.googleapis.com/fcm/send/second-device'))
            ->assertCreated()
            ->assertJsonPath('data.channel', 'push');

        // Email chosen again: neither renewing a device nor switching on a further one undoes it.
        $this->portal('PUT', 'profile', ['notification_channel' => 'email'])->assertOk();
        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertOk()
            ->assertJsonPath('data.channel', 'email');
        $this->portal('POST', 'push/subscriptions', $this->subscription('https://fcm.googleapis.com/fcm/send/third-device'))
            ->assertCreated()
            ->assertJsonPath('data.channel', 'email');
        $this->assertSame(PortalNotificationChannel::Email, $this->user->fresh()->notification_channel);
    }

    public function test_a_first_device_after_the_others_ended_adds_push_again(): void
    {
        $this->fakePush();
        $this->pushDevice($this->user, $this->expiredSession($this->user));
        $this->signInToPortal($this->user);

        // The old device's session ran out: the list is empty, so this is the first device again.
        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertCreated()
            ->assertJsonPath('data.channel', 'both');
    }

    public function test_an_account_keeps_at_most_ten_devices(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);

        $devices = collect(range(1, 10))->map(function () {
            $this->travel(1)->minutes();

            return $this->pushDevice($this->user);
        });

        $this->travel(1)->minutes();
        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertCreated()
            ->assertJsonCount(10, 'data.devices');

        // The oldest goes; the nine others and this browser stay.
        $this->assertSame(10, $this->user->pushSubscriptions()->count());
        $this->assertModelMissing($devices->first());
        $devices->skip(1)->each(fn (PortalPushSubscription $device) => $this->assertModelExists($device));
        $this->assertSame(1, $this->user->pushSubscriptions()->where('endpoint_hash', hash('sha256', self::ENDPOINT))->count());
    }

    public function test_switching_push_off_removes_only_this_accounts_device(): void
    {
        $this->fakePush();
        $theirs = $this->pushDevice(PortalUser::factory()->create());
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();

        // Another account's endpoint, or one nobody has: nothing changes, nothing is audited.
        $this->portal('DELETE', 'push/subscriptions', ['endpoint' => $theirs->endpoint])
            ->assertOk()
            ->assertJsonCount(1, 'data.devices');
        $this->portal('DELETE', 'push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/nobody'])
            ->assertOk()
            ->assertJsonCount(1, 'data.devices');
        $this->assertModelExists($theirs);
        $this->assertSame(0, $this->audited(AuditEvent::PortalPushDisabled));

        $this->portal('DELETE', 'push/subscriptions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->portal('DELETE', 'push/subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJsonCount(0, 'data.devices');

        $this->assertSame(0, $this->user->pushSubscriptions()->count());
        $this->assertModelExists($theirs);
        $entry = AuditLog::query()->where('event', AuditEvent::PortalPushDisabled)->sole();
        $this->assertSame($this->user->id, $entry->portal_user_id);
        $this->assertStringNotContainsString('fcm.googleapis.com', (string) json_encode($entry->properties));
    }

    public function test_a_test_notice_goes_to_this_device_only(): void
    {
        $push = $this->fakePush();
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();
        $phone = $this->pushDevice($this->user);

        $this->portal('POST', 'push/test', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertExactJson(['data' => ['sent' => true]]);

        $this->assertCount(1, $push->sent);
        $this->assertSame(self::ENDPOINT, $push->sent[0]['endpoint']);
        $this->assertSame(__('portal.push.test.title'), $push->sent[0]['message']->title);
        $this->assertSame(__('portal.push.test.body'), $push->sent[0]['message']->body);
        $this->assertSame('/profile', $push->sent[0]['message']->url);
        $this->assertNotNull($this->user->pushSubscriptions()->where('endpoint_hash', hash('sha256', self::ENDPOINT))->sole()->last_used_at);
        $this->assertNull($phone->fresh()->last_used_at);
    }

    public function test_a_test_notice_needs_a_live_device_of_this_account(): void
    {
        $push = $this->fakePush();
        $theirs = $this->pushDevice(PortalUser::factory()->create());
        $ended = $this->pushDevice($this->user, $this->expiredSession($this->user));
        $this->signInToPortal($this->user);

        foreach ([$theirs->endpoint, $ended->endpoint, self::ENDPOINT] as $endpoint) {
            $this->portal('POST', 'push/test', ['endpoint' => $endpoint])
                ->assertNotFound()
                ->assertJsonPath('code', 'push_not_on');
        }

        $this->portal('POST', 'push/test', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertSame([], $push->sent);
    }

    public function test_a_subscription_the_push_service_dropped_is_removed(): void
    {
        $push = $this->fakePush();
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();

        $push->expired = [self::ENDPOINT];

        $this->portal('POST', 'push/test', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJsonPath('data.sent', false);

        $this->assertSame(0, PortalPushSubscription::query()->count());
        $this->portal('GET', 'push')->assertOk()->assertJsonCount(0, 'data.devices');
    }

    public function test_switching_on_and_test_notices_are_limited_per_account(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);

        for ($i = 0; $i < 10; $i++) {
            $this->portal('POST', 'push/subscriptions', $this->subscription())->assertSuccessful();
        }

        $this->portal('POST', 'push/subscriptions', $this->subscription())
            ->assertStatus(429)
            ->assertJsonPath('message', __('portal.errors.too_many'));
        $this->portal('POST', 'push/test', ['endpoint' => self::ENDPOINT])->assertStatus(429);

        // Switching off is never held back.
        $this->portal('DELETE', 'push/subscriptions', ['endpoint' => self::ENDPOINT])->assertOk();

        $this->travel(11)->minutes();
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();
    }

    public function test_guests_get_nothing(): void
    {
        $this->fakePush();
        $device = $this->pushDevice($this->user, null, self::ENDPOINT);

        $this->portal('GET', 'push')->assertUnauthorized();
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertUnauthorized();
        $this->portal('DELETE', 'push/subscriptions', ['endpoint' => self::ENDPOINT])->assertUnauthorized();
        $this->portal('POST', 'push/test', ['endpoint' => self::ENDPOINT])->assertUnauthorized();

        $this->assertModelExists($device);
    }

    public function test_an_astrologers_session_does_not_reach_push(): void
    {
        $this->fakePush();
        $this->actingAs($this->vega);

        // Not on the application's host at all ...
        $this->getJson('/api/portal/v1/push')->assertNotFound();
        $this->postJson('/api/portal/v1/push/subscriptions', $this->subscription())->assertNotFound();
        $this->postJson('/api/portal/v1/push/test', ['endpoint' => self::ENDPOINT])->assertNotFound();

        // ... and on the portal's host the astrologer is nobody.
        $this->getJson($this->portalUrl('api/portal/v1/push'))->assertUnauthorized();

        $this->assertSame(0, PortalPushSubscription::query()->count());
    }

    public function test_signing_out_stops_push_to_this_device_only(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();
        $phone = $this->pushDevice($this->user);

        $this->portal('POST', 'sign-out')->assertNoContent();

        $this->assertSame([$phone->id], PortalPushSubscription::query()->pluck('id')->all());
    }

    public function test_signing_in_again_in_the_same_browser_keeps_push_on(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();

        // An emailed sign-in link (or another practice's invitation) opened in this browser.
        $this->signInToPortal($this->user);

        $device = PortalPushSubscription::query()->sole();
        $this->assertSame($this->latestPortalSessionId($this->user), $device->portal_session_id);
        $this->assertTrue($this->portal('GET', 'push')->json('data.devices.0.current'));
    }

    public function test_someone_else_signing_in_on_this_browser_stops_the_previous_accounts_push(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();

        $boris = $this->portalUserFor(Client::factory()->inWorkspace($this->vega->current_workspace_id)->create());
        $this->signInToPortal($boris);

        $this->assertSame(0, PortalPushSubscription::query()->count());
    }

    public function test_signing_out_everywhere_stops_push_to_every_device(): void
    {
        $this->fakePush();
        $this->signInToPortal($this->user);
        $this->portal('POST', 'push/subscriptions', $this->subscription())->assertCreated();
        $this->pushDevice($this->user);
        $theirs = $this->pushDevice(PortalUser::factory()->create());

        $this->portal('DELETE', 'sessions')->assertNoContent();

        $this->assertSame(0, $this->user->pushSubscriptions()->count());
        $this->assertModelExists($theirs);
    }

    public function test_revoking_the_last_active_link_stops_push(): void
    {
        $device = $this->pushDevice($this->user);

        $this->actingAs($this->vega)->deleteJson("/api/v1/clients/{$this->ana->id}/portal")->assertOk();

        $this->assertModelMissing($device);
    }

    public function test_revoking_one_practice_keeps_push_while_another_link_is_active(): void
    {
        $luna = User::factory()->withWorkspace(['name' => 'Luna Studio'])->create();
        $anaThere = Client::factory()->inWorkspace($luna->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->portalUserFor($anaThere, $this->user);
        $device = $this->pushDevice($this->user);

        $this->actingAs($this->vega)->deleteJson("/api/v1/clients/{$this->ana->id}/portal")->assertOk();

        $this->assertModelExists($device);
    }

    public function test_deleting_the_client_deletes_the_account_with_its_devices(): void
    {
        Storage::fake('attachments');
        $device = $this->pushDevice($this->user);

        $this->actingAs($this->vega);
        app(CurrentWorkspace::class)->run(
            Workspace::query()->findOrFail($this->vega->current_workspace_id),
            fn () => app(DeleteClient::class)->handle($this->ana),
        );

        $this->assertNull(PortalUser::query()->find($this->user->id));
        $this->assertModelMissing($device);
    }

    public function test_prune_removes_devices_whose_session_ended(): void
    {
        $live = $this->pushDevice($this->user);
        $this->pushDevice($this->user, 'a-session-that-ended');
        $this->pushDevice($this->user, $this->expiredSession($this->user));

        $this->assertSame(2, app(Retention::class)->apply(CarbonImmutable::now(), dryRun: true)['portal_push_subscriptions']);
        $this->artisan('data:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, PortalPushSubscription::query()->count());

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame([$live->id], PortalPushSubscription::query()->pluck('id')->all());
    }

    public function test_the_client_chooses_email_push_or_both_in_profile(): void
    {
        $this->signInToPortal($this->user);
        $this->portal('PUT', 'profile', ['name' => 'Ana', 'appointment_reminders' => false])->assertOk();

        $this->portal('GET', 'profile')->assertOk()->assertJsonPath('data.notification_channel', 'email');

        foreach (['push', 'both', 'email'] as $channel) {
            $this->portal('PUT', 'profile', ['notification_channel' => $channel])
                ->assertOk()
                ->assertJsonPath('data.notification_channel', $channel)
                ->assertJsonPath('data.name', 'Ana')
                ->assertJsonPath('data.appointment_reminders', false);
            $this->assertSame($channel, $this->user->fresh()->notification_channel->value);
        }

        $this->assertSame(
            [['fields' => ['notification_channel']], ['fields' => ['notification_channel']], ['fields' => ['notification_channel']]],
            AuditLog::query()
                ->where('event', AuditEvent::PortalProfileUpdated)
                ->where('portal_user_id', $this->user->id)
                ->orderBy('id')
                ->get()
                ->skip(1)
                ->pluck('properties')
                ->values()
                ->all(),
        );

        // Saving the rest of the profile without it leaves it as it is.
        $this->portal('PUT', 'profile', ['name' => 'Ana Petrović'])
            ->assertOk()
            ->assertJsonPath('data.notification_channel', 'email');
        $this->portal('PUT', 'profile', ['notification_channel' => 'push'])->assertOk();
        $this->portal('PUT', 'profile', ['appointment_reminders' => true])
            ->assertOk()
            ->assertJsonPath('data.notification_channel', 'push');
        $this->portal('GET', 'profile')->assertOk()->assertJsonPath('data.notification_channel', 'push');
    }

    public function test_only_email_push_or_both_is_taken(): void
    {
        $this->signInToPortal($this->user);

        foreach (['sms', 'PUSH', '', null] as $channel) {
            $this->portal('PUT', 'profile', ['notification_channel' => $channel])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('notification_channel');
        }

        $this->assertSame(PortalNotificationChannel::Email, $this->user->fresh()->notification_channel);
    }

    public function test_the_practice_export_leaves_push_out(): void
    {
        Storage::fake('attachments');
        Storage::fake('exports');
        $device = $this->pushDevice($this->user);
        $path = Storage::disk('exports')->path('push-test.zip');
        @mkdir(dirname($path), 0777, true);

        app(PracticeExport::class)->write(Workspace::query()->findOrFail($this->vega->current_workspace_id), $path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = '';
        $everything = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names .= $zip->getNameIndex($i)."\n";
            $everything .= $zip->getFromIndex($i);
        }
        $zip->close();

        $this->assertStringContainsString('portal_access.json', $names);
        $this->assertStringNotContainsString('push', $names);
        $this->assertStringNotContainsString('push_subscription', $everything);
        $this->assertStringNotContainsString(Str::afterLast($device->endpoint, '/'), $everything);
        $this->assertStringNotContainsString($device->endpoint_hash, $everything);
        $this->assertStringNotContainsString('AuthSecretOfTheBrowser', $everything);
        $this->assertStringNotContainsString('notification_channel', $everything);
    }
}
