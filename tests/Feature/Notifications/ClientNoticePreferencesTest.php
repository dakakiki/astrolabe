<?php

namespace Tests\Feature\Notifications;

use App\Actions\Booking\CancelPortalAppointment;
use App\Actions\Booking\ConfirmAppointment;
use App\Enums\PortalNotificationChannel;
use App\Support\Notifications\ClientNotificationPreferences;
use App\Support\Portal\Push\FakePushSender;
use App\Support\Portal\Push\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * The client chooses email and push per notice (Portal → Profile → Notifications;
 * docs/spec/12, the user's decision of 18 Nov 2026): neither turns a notice off,
 * the confirmations of their own steps included; push without a device comes by
 * email. What the account chose before (one channel for all, the reminder switch)
 * is where every notice starts. The real actions run (the queue is sync).
 */
class ClientNoticePreferencesTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    private FakePushSender $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        $this->push = $this->fakePush();
        Exceptions::fake();
    }

    protected function assertPostConditions(): void
    {
        Exceptions::assertNothingReported();
    }

    /** @return list<string> subjects of the emails the client got */
    private function emails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->filter(fn (SentMessage $sent) => collect($sent->getEnvelope()->getRecipients())
                ->contains(fn (Address $to) => $to->getAddress() === $this->portalUser->email))
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }

    /** @return list<string> push titles the client's devices got */
    private function pushes(): array
    {
        return array_map(fn (PushMessage $message) => $message->title, $this->push->messagesFor($this->portalUser->id));
    }

    /** @param array<string, array<string, bool>> $notices */
    private function choose(array $notices): void
    {
        $this->portalUser->forceFill(['notification_preferences' => ClientNotificationPreferences::of($this->portalUser->fresh())->with($notices)->toArray()])->save();
    }

    public function test_every_notice_starts_from_the_choice_made_before_and_is_saved_on_its_own(): void
    {
        $this->portalUser->forceFill(['notification_channel' => PortalNotificationChannel::Both, 'appointment_reminders' => false])->save();
        $this->signInToPortal($this->portalUser);

        $profile = $this->portal('GET', 'profile')->assertOk();
        $this->assertSame(ClientNotificationPreferences::KINDS, array_keys($profile->json('data.notifications')));
        $this->assertSame(['mail' => true, 'push' => true], $profile->json('data.notifications.booked'));
        $this->assertSame(['mail' => false, 'push' => false], $profile->json('data.notifications.reminder'));
        $profile->assertJsonPath('data.appointment_reminders', false);

        $this->portal('PUT', 'profile', ['notifications' => ['booked' => ['mail' => false, 'push' => false], 'reminder' => ['push' => true]]])
            ->assertOk()
            ->assertJsonPath('data.notifications.booked', ['mail' => false, 'push' => false])
            ->assertJsonPath('data.notifications.reminder', ['mail' => false, 'push' => true])
            ->assertJsonPath('data.notifications.confirmed', ['mail' => true, 'push' => true])
            ->assertJsonPath('data.appointment_reminders', true);

        $this->portal('PUT', 'profile', ['notifications' => ['invitation' => ['mail' => false]]])
            ->assertUnprocessable()->assertJsonValidationErrors('notifications');
        $this->portal('PUT', 'profile', ['notifications' => ['booked' => ['sms' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('notifications.booked');

        // The old one channel for all starts every notice over from it.
        $this->portal('PUT', 'profile', ['notification_channel' => 'email'])
            ->assertOk()
            ->assertJsonPath('data.notifications.booked', ['mail' => true, 'push' => false])
            ->assertJsonPath('data.notifications.reminder', ['mail' => false, 'push' => false]);
    }

    public function test_a_notice_turned_off_sends_nothing_and_push_without_a_device_comes_by_email(): void
    {
        $this->policy(['requires_confirmation' => true]);
        $this->choose([
            'requested' => ['mail' => false, 'push' => false],
            'confirmed' => ['mail' => false, 'push' => true],
        ]);

        $appointment = $this->book();
        $this->assertSame([], $this->emails());

        // No device has push on: wanted as a notification, it comes by email.
        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($appointment));
        $this->assertSame(['Confirmed: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertSame([], $this->pushes());
    }

    public function test_with_a_device_each_notice_goes_where_it_was_asked_to(): void
    {
        $this->pushDevice($this->portalUser);
        $this->choose([
            'booked' => ['mail' => true, 'push' => false],
            'cancelled_by_you' => ['mail' => false, 'push' => true],
        ]);

        $appointment = $this->book();
        $this->inPractice(fn () => app(CancelPortalAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser->fresh(),
            $appointment->fresh(),
            null,
        ));

        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertSame(['You cancelled your appointment'], $this->pushes());
    }

    public function test_the_reminder_turned_off_is_not_sent(): void
    {
        $this->choose(['reminder' => ['mail' => false, 'push' => false]]);
        $this->appointmentByThePractice('2026-10-05 13:00');

        $this->travelTo('2026-10-04 13:00:30');
        $this->artisan('notifications:send-client-reminders')->assertSuccessful();

        $this->assertSame([], array_filter($this->emails(), fn (string $subject) => str_starts_with($subject, 'Reminder:')));
    }

    public function test_the_first_device_adds_push_beside_email_but_keeps_what_was_turned_off(): void
    {
        $this->choose(['moved' => ['mail' => false, 'push' => false]]);
        $this->signInToPortal($this->portalUser);

        $this->portal('POST', 'push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/first-device',
            'keys' => ['p256dh' => 'BKeyOfThisBrowser', 'auth' => 'authToken'],
        ])->assertCreated();

        $preferences = ClientNotificationPreferences::of($this->portalUser->fresh());
        $this->assertTrue($preferences->wants('booked', 'push'));
        $this->assertTrue($preferences->wants('booked', 'mail'));
        $this->assertFalse($preferences->wantsAny('moved'));
    }
}
