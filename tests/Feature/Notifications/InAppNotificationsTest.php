<?php

namespace Tests\Feature\Notifications;

use App\Actions\Workspaces\DeletePractice;
use App\Models\AppNotification;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\PortalBookingNotice;
use App\Support\Retention\Retention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * Notifications by kind and channel (docs/spec/09, "Obaveštenja"; the user's decision of
 * 18 Nov 2026): every notice an astrologer may turn off has email and "in the app" (the
 * bell), chosen per notice in Settings → Notifications; neither is off. The real actions
 * run and the listeners fire (the queue is sync); mail lands in the array mailer.
 */
class InAppNotificationsTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        // The listeners rescue() a failing notice: without this a broken channel would pass unseen.
        Exceptions::fake();
    }

    protected function assertPostConditions(): void
    {
        Exceptions::assertNothingReported();
    }

    /** @param array<string, array<string, bool>> $channels */
    private function choose(array $channels): void
    {
        $this->actingAs($this->astrologer)
            ->putJson('/api/v1/notification-preferences', ['channels' => $channels])
            ->assertOk();
    }

    /** @return list<string> subjects of the emails the astrologer got */
    private function astrologerEmails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->filter(fn (SentMessage $sent) => collect($sent->getEnvelope()->getRecipients())
                ->contains(fn (Address $to) => $to->getAddress() === $this->astrologer->email))
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }

    public function test_each_notice_has_its_own_channels_and_the_old_switches_still_work(): void
    {
        $this->choose(['portal_moved' => ['mail' => false], 'task_digest' => ['mail' => false, 'app' => false]]);

        $preferences = $this->astrologer->fresh()->notificationPreferences();
        $this->assertFalse($preferences->wants('portal_moved', 'mail'));
        $this->assertTrue($preferences->wants('portal_moved', 'app'));
        $this->assertTrue($preferences->wants('portal_booked', 'mail'));
        $this->assertFalse($preferences->wantsAny('task_digest'));
        // No morning count is planned when it is off on both channels.
        $this->assertNull($this->astrologer->fresh()->next_digest_at);

        $this->actingAs($this->astrologer)->getJson('/api/v1/me')
            ->assertJsonPath('data.user.notification_preferences.channels.portal_moved', ['mail' => false, 'app' => true])
            ->assertJsonPath('data.user.notification_preferences.task_digest', false);

        // Unknown notices and channels a notice cannot take are refused.
        $this->actingAs($this->astrologer)->putJson('/api/v1/notification-preferences', ['channels' => ['invoice_paid' => ['mail' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('channels');
        $this->actingAs($this->astrologer)->putJson('/api/v1/notification-preferences', ['channels' => ['practice_deleted' => ['app' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('channels.practice_deleted');
        $this->actingAs($this->astrologer)->putJson('/api/v1/notification-preferences', ['channels' => ['portal_booked' => ['mail' => 'often']]])
            ->assertUnprocessable()->assertJsonValidationErrors('channels.portal_booked.mail');

        // The old switch sets every channel of its kinds.
        $this->actingAs($this->astrologer)->putJson('/api/v1/notification-preferences', ['portal_bookings' => true])->assertOk();
        $this->assertTrue($this->astrologer->fresh()->notificationPreferences()->wants('portal_moved', 'mail'));
    }

    public function test_a_booking_lands_in_the_bell_and_by_email_as_chosen(): void
    {
        $this->choose(['portal_booked' => ['mail' => false, 'app' => true]]);

        $appointment = $this->book();

        $this->assertSame([], $this->astrologerEmails());
        $notice = AppNotification::query()->sole();
        $this->assertSame($this->astrologer->id, $notice->user_id);
        $this->assertSame($this->practice->id, $notice->workspace_id);
        $this->assertSame(PortalBookingNotice::BOOKED, substr($notice->kind, strlen('portal_')));
        $this->assertSame($appointment->id, $notice->data['appointment_id']);
        $this->assertSame('2026-10-05T13:00:00Z', $notice->data['starts_at']);
        // Nothing personal is copied: no name, no message.
        $this->assertStringNotContainsString('Ana', json_encode($notice->data));
        $this->assertStringNotContainsString('Viber', json_encode($notice->data));

        // Both off: neither email nor bell.
        $this->choose(['portal_booked' => ['mail' => false, 'app' => false]]);
        $this->book('2026-10-06 13:00');
        $this->assertSame(1, AppNotification::query()->count());

        // Email only.
        $this->choose(['portal_booked' => ['mail' => true, 'app' => false]]);
        $this->book('2026-10-07 13:00');
        $this->assertSame(1, AppNotification::query()->count());
        $this->assertCount(1, $this->astrologerEmails());
    }

    public function test_the_bell_lists_the_open_practices_notices_with_the_clients_name(): void
    {
        $this->choose(['portal_booked' => ['mail' => false, 'app' => true]]);
        $this->book();
        $this->book('2026-10-06 13:00');

        // Someone else's, and one about another practice of the same person, stay out.
        $other = User::factory()->withWorkspace()->create();
        AppNotification::query()->create(['user_id' => $other->id, 'workspace_id' => $other->current_workspace_id, 'kind' => 'task_digest', 'data' => ['today' => 1], 'created_at' => now()]);
        $elsewhere = AppNotification::query()->create(['user_id' => $this->astrologer->id, 'workspace_id' => $other->current_workspace_id, 'kind' => 'task_digest', 'data' => ['today' => 2], 'created_at' => now()]);
        AppNotification::query()->create(['user_id' => $this->astrologer->id, 'workspace_id' => null, 'kind' => 'unknown_account_notice', 'data' => [], 'created_at' => now()->subMinute()]);

        $list = $this->actingAs($this->astrologer)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(3, $list->json('meta.unread'));
        $this->assertSame(['portal_booked', 'portal_booked', 'unknown_account_notice'], array_column($list->json('data'), 'kind'));
        $this->assertSame('Ana Marković', $list->json('data.0.client.full_name'));
        $this->assertNull($list->json('data.2.client'));

        $first = $list->json('data.0.id');
        $this->actingAs($this->astrologer)->postJson("/api/v1/notifications/{$first}/read")->assertOk();
        $this->actingAs($this->astrologer)->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 2);
        $this->actingAs($this->astrologer)->postJson("/api/v1/notifications/{$elsewhere->id}/read")->assertNotFound();

        $this->actingAs($this->astrologer)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 2);
        $this->actingAs($this->astrologer)->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 0);
        $this->assertNull($elsewhere->fresh()->read_at);

        // The appointment gone since (a client deleted takes theirs along): the notice stays, without a name.
        $this->inPractice(fn () => Appointment::query()->get()->each->delete());
        $this->assertNull($this->actingAs($this->astrologer)->getJson('/api/v1/notifications')->json('data.0.client'));
    }

    public function test_a_reminder_goes_to_the_bell_only_when_email_is_off(): void
    {
        $this->choose(['appointment_reminder' => ['mail' => false, 'app' => true]]);
        $appointment = $this->book();

        // The usual reminder is a day before: 5 Oct 13:00 UTC → 4 Oct 13:00 UTC.
        $this->travelTo('2026-10-04 13:00:30');
        $this->artisan('notifications:send-reminders')->assertSuccessful();

        $reminder = AppNotification::query()->where('kind', 'appointment_reminder')->sole();
        $this->assertSame($appointment->id, $reminder->data['appointment_id']);
        $this->assertNotContains(true, array_map(fn (string $subject) => str_starts_with($subject, 'Reminder:'), $this->astrologerEmails()));
    }

    public function test_the_bell_goes_with_the_practice_and_after_ninety_days(): void
    {
        $this->choose(['portal_booked' => ['mail' => false, 'app' => true], 'practice_deleted' => ['mail' => false]]);
        $this->book();
        AppNotification::query()->create(['user_id' => $this->astrologer->id, 'workspace_id' => null, 'kind' => 'old', 'data' => [], 'created_at' => CarbonImmutable::now()->subDays(91)]);

        app(Retention::class)->apply(CarbonImmutable::now());
        $this->assertSame(['portal_booked'], AppNotification::query()->pluck('kind')->all());

        $before = count($this->astrologerEmails());
        $counts = app(DeletePractice::class)->handle($this->practice->fresh());

        $this->assertSame(1, $counts['app_notifications']);
        $this->assertSame(0, AppNotification::query()->count());
        // "Practice deleted" was turned off: no email about it.
        $this->assertCount($before, $this->astrologerEmails());
    }
}
