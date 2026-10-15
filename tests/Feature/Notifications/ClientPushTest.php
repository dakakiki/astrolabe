<?php

namespace Tests\Feature\Notifications;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\SaveAppointment;
use App\Actions\Booking\CancelPortalAppointment;
use App\Actions\Booking\ConfirmAppointment;
use App\Actions\Booking\DeclineAppointment;
use App\Actions\Booking\RescheduleAppointment;
use App\Enums\PortalAccessStatus;
use App\Enums\PortalNotificationChannel;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Notifications\Channels\PortalPushChannel;
use App\Notifications\ClientBookingConfirmed;
use App\Support\Notifications\ClientAppointmentFacts;
use App\Support\Portal\Push\FakePushSender;
use App\Support\Portal\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * The client's notices about their appointments as web push (Phase 9c,
 * docs/spec/12, "Web push"): by email, push or both as the client chose in
 * Profile — email whenever no live device can take the push — with a generic
 * title and the time on the client's clock, never the practice, the service
 * or anybody's words. The real actions run and the listeners fire (the queue
 * is sync); the mail lands in the array mailer, pushes in FakePushSender.
 */
class ClientPushTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    private FakePushSender $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        $this->push = $this->fakePush();

        // The listeners rescue() a failing notice: without this a broken push would pass unseen.
        Exceptions::fake();
    }

    protected function assertPostConditions(): void
    {
        Exceptions::assertNothingReported();
    }

    private function hearBy(PortalNotificationChannel $channel): void
    {
        $this->portalUser->forceFill(['notification_channel' => $channel])->save();
    }

    /**
     * The subjects of the emails the client got.
     *
     * @return list<string>
     */
    private function emails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->filter(fn (SentMessage $sent) => collect($sent->getEnvelope()->getRecipients())
                ->contains(fn (Address $to) => $to->getAddress() === $this->portalUser->email))
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }

    /**
     * What the client's devices were sent, one row per device: [title, body, url, tag].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    private function pushes(): array
    {
        return array_map(
            fn (PushMessage $message) => [$message->title, $message->body, $message->url, $message->tag],
            $this->push->messagesFor($this->portalUser->id),
        );
    }

    private function tag(Appointment $appointment): string
    {
        return 'appointment-'.$appointment->id;
    }

    private function sendDueReminders(): void
    {
        $this->artisan('notifications:send-client-reminders')->assertSuccessful();
    }

    private function moveInThePortal(Appointment $appointment, string $utcStart): void
    {
        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser->fresh(),
            $appointment,
            CarbonImmutable::parse($utcStart, 'UTC'),
        ));
    }

    private function cancelInThePortal(Appointment $appointment, ?string $reason = null): void
    {
        $this->inPractice(fn () => app(CancelPortalAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser->fresh(),
            $appointment->fresh(),
            $reason,
        ));
    }

    private function cancelByThePractice(Appointment $appointment, ?string $reason = null, bool $notifyClient = true): void
    {
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($appointment->fresh(), $reason, notifyClient: $notifyClient));
    }

    public function test_by_default_the_client_hears_by_email_even_with_push_on_a_device(): void
    {
        $this->pushDevice($this->portalUser);

        $this->book();

        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_both_sends_the_email_and_a_push_to_every_live_device(): void
    {
        $this->hearBy(PortalNotificationChannel::Both);
        $phone = $this->pushDevice($this->portalUser);
        $laptop = $this->pushDevice($this->portalUser);

        $appointment = $this->book();

        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertEqualsCanonicalizing([$phone->endpoint, $laptop->endpoint], array_column($this->push->sent, 'endpoint'));
        $this->assertSame(array_fill(0, 2, ['Appointment booked', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($appointment)]), $this->pushes());
    }

    public function test_push_alone_sends_no_email_while_a_device_has_it_on(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);

        $this->book();

        $this->assertSame([], $this->emails());
        $this->assertCount(1, $this->pushes());
    }

    public function test_push_alone_without_a_device_falls_back_to_email(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);

        $this->book();

        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_a_device_whose_portal_session_expired_or_ended_counts_as_none(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $sessions = DB::table(config('portal.session.table'));

        // Unused for longer than a portal session lives.
        $idle = $this->pushDevice($this->portalUser);
        $sessions->clone()->where('id', $idle->portal_session_id)->update([
            'last_activity' => now()->subMinutes((int) config('portal.session.lifetime') + 1)->getTimestamp(),
        ]);
        $this->book();

        // Its session row gone (signed out, pruned).
        $sessions->clone()->where('id', $idle->portal_session_id)->delete();
        $this->book('2026-10-06 13:00');

        // A session of another account does not make it live either.
        $other = $this->portalUserFor(Client::factory()->inWorkspace($this->practice->id)->create(['email' => 'other@example.com']));
        $this->pushDevice($this->portalUser, $this->pushDevice($other)->portal_session_id);
        $this->book('2026-10-07 13:00');

        $this->assertCount(3, $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_without_vapid_keys_every_notice_goes_by_email(): void
    {
        config(['portal.push.public_key' => null, 'portal.push.private_key' => null]);
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);

        $this->book();

        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_a_booking_is_pushed_with_the_time_on_the_clients_clock(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);

        // 15:00 in Belgrade, 9:00 in New York.
        $appointment = $this->book();

        $this->assertSame([
            ['Appointment booked', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($appointment)],
        ], $this->pushes());
    }

    public function test_a_request_and_the_practices_answers_are_pushed(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);
        $this->policy(['requires_confirmation' => true]);

        $confirmed = $this->book();
        $declined = $this->book('2026-10-06 13:00');
        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($confirmed));
        $this->inPractice(fn () => app(DeclineAppointment::class)->handle($declined, 'That week is fully booked.'));

        $this->assertSame([
            ['Booking request sent', 'Mon, Oct 5, 2026 at 9:00 AM · waiting for confirmation', '/appointments', $this->tag($confirmed)],
            ['Booking request sent', 'Tue, Oct 6, 2026 at 9:00 AM · waiting for confirmation', '/appointments', $this->tag($declined)],
            ['Booking confirmed', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($confirmed)],
            ['Booking not confirmed', 'Tue, Oct 6, 2026 at 9:00 AM · open the portal for details', '/appointments', $this->tag($declined)],
        ], $this->pushes());
        $this->assertSame([], $this->emails());
    }

    public function test_moves_by_the_client_and_by_the_practice_are_pushed_with_the_new_time(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);
        $mine = $this->book();
        $theirs = $this->appointmentByThePractice('2026-10-07 13:00');
        $quiet = $this->appointmentByThePractice('2026-10-09 13:00');

        $this->moveInThePortal($mine, '2026-10-06 14:00');
        $this->inPractice(fn () => app(SaveAppointment::class)->handle($theirs, ['starts_at' => '2026-10-08T16:00', 'timezone' => 'Europe/Belgrade']));
        $this->inPractice(fn () => app(SaveAppointment::class)->handle($quiet, ['starts_at' => '2026-10-09T16:00', 'timezone' => 'Europe/Belgrade'], notifyClient: false));

        $this->assertSame([
            ['Appointment booked', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($mine)],
            ['You moved your appointment', 'Tue, Oct 6, 2026 at 10:00 AM', '/appointments', $this->tag($mine)],
            ['Appointment moved', 'Thu, Oct 8, 2026 at 10:00 AM', '/appointments', $this->tag($theirs)],
        ], $this->pushes());
    }

    public function test_cancellations_by_the_client_and_by_the_practice_are_pushed(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);
        $mine = $this->book();
        $theirs = $this->appointmentByThePractice('2026-10-07 13:00');
        $quiet = $this->appointmentByThePractice('2026-10-09 13:00');

        $this->cancelInThePortal($mine, 'Feeling unwell, sorry.');
        $this->cancelByThePractice($theirs, 'Internal: double booked');
        $this->cancelByThePractice($quiet, null, notifyClient: false);

        $this->assertSame([
            ['Appointment booked', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($mine)],
            ['You cancelled your appointment', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($mine)],
            ['Appointment cancelled', 'Wed, Oct 7, 2026 at 9:00 AM', '/appointments', $this->tag($theirs)],
        ], $this->pushes());
    }

    public function test_today_and_tomorrow_are_the_clients_today_and_tomorrow(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);

        // It is Friday 2 October, 4:00 in New York and 10:00 in Belgrade.
        $tonight = $this->appointmentByThePractice('2026-10-03 02:00');    // Fri 22:00 NY, Sat 04:00 Belgrade
        $tomorrow = $this->appointmentByThePractice('2026-10-03 13:00');   // Sat 09:00 NY
        $lateTomorrow = $this->appointmentByThePractice('2026-10-04 03:00'); // Sat 23:00 NY, Sun 05:00 Belgrade

        foreach ([$tonight, $tomorrow, $lateTomorrow] as $appointment) {
            $this->cancelByThePractice($appointment);
        }

        // Until the client picks a zone, the practice's clock.
        $this->portalUser->forceFill(['timezone' => null])->save();
        $dawn = $this->appointmentByThePractice('2026-10-03 02:30');      // Sat 04:30 Belgrade
        $this->cancelByThePractice($dawn);

        $this->assertSame([
            'Today at 10:00 PM',
            'Tomorrow at 9:00 AM',
            'Tomorrow at 11:00 PM',
            'Tomorrow at 4:30 AM',
        ], array_column($this->pushes(), 1));
    }

    public function test_the_reminder_is_pushed_a_day_before_and_only_once(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);
        $appointment = $this->book();

        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDueReminders();
        $this->sendDueReminders();

        $this->assertSame([
            ['Appointment booked', 'Mon, Oct 5, 2026 at 9:00 AM', '/appointments', $this->tag($appointment)],
            ['Appointment reminder', 'Tomorrow at 9:00 AM', '/appointments', $this->tag($appointment)],
        ], $this->pushes());
        $this->assertSame([], $this->emails());
    }

    public function test_reminders_switched_off_send_neither_email_nor_push(): void
    {
        $this->hearBy(PortalNotificationChannel::Both);
        $this->pushDevice($this->portalUser);
        $this->book();
        $this->portalUser->forceFill(['appointment_reminders' => false])->save();

        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDueReminders();

        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
        $this->assertSame(['Appointment booked'], array_column($this->pushes(), 0));
    }

    public function test_a_revoked_link_gets_nothing_on_any_channel(): void
    {
        $this->hearBy(PortalNotificationChannel::Both);
        $this->pushDevice($this->portalUser);
        $appointment = $this->appointmentByThePractice('2026-10-05 13:00');
        $later = $this->appointmentByThePractice('2026-10-07 13:00');

        // The device stays (revoking by hand here, not through RevokePortalAccess): the link decides.
        PortalAccess::query()->acrossPractices()->where('client_id', $this->client->id)
            ->update(['status' => PortalAccessStatus::Revoked->value, 'revoked_at' => now()]);

        $this->cancelByThePractice($later);
        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDueReminders();

        $this->assertNotNull($appointment->fresh()->client_reminder_sent_at);
        $this->assertSame([], $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_a_push_never_names_the_practice_the_service_or_anybodys_words(): void
    {
        $this->hearBy(PortalNotificationChannel::Both);
        $this->pushDevice($this->portalUser);
        $this->policy(['requires_confirmation' => true]);

        // The client's message to the astrologer goes with both bookings.
        $kept = $this->book();
        $declined = $this->book('2026-10-06 13:00');
        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($kept));
        $this->inPractice(fn () => app(DeclineAppointment::class)->handle($declined, 'That week is fully booked.'));
        $this->moveInThePortal($kept->fresh(), '2026-10-06 14:00');
        $this->cancelInThePortal($kept, 'Feeling unwell, sorry.');

        // The practice's own appointments carry its internal notes.
        $theirs = $this->appointmentByThePractice('2026-10-07 13:00');
        $reminded = $this->appointmentByThePractice('2026-10-09 13:00');
        $this->inPractice(fn () => app(SaveAppointment::class)->handle($theirs, ['starts_at' => '2026-10-08T16:00', 'timezone' => 'Europe/Belgrade']));
        $this->cancelByThePractice($theirs, 'Internal: double booked');
        $this->travelTo('2026-10-08 13:00:30');
        $this->sendDueReminders();

        $this->assertSame([
            'Booking request sent',
            'Booking request sent',
            'Booking confirmed',
            'Booking not confirmed',
            'You moved your appointment',
            'You cancelled your appointment',
            'Appointment moved',
            'Appointment cancelled',
            'Appointment reminder',
        ], array_column($this->pushes(), 0));
        $this->assertSame($this->tag($reminded), array_column($this->pushes(), 3)[8]);

        foreach ($this->push->messagesFor($this->portalUser->id) as $message) {
            foreach (['Vega', 'Vera', 'Natal', 'Ana', 'Marković', 'Viber', 'fully booked', 'unwell', 'double booked', 'Saturn', 'Internal'] as $private) {
                $this->assertStringNotContainsString($private, $message->payload());
            }
        }

        // The emails do name the practice: only the push is this generic.
        $this->assertCount(9, $this->emails());
    }

    public function test_a_dropped_subscription_is_deleted_and_the_other_device_still_gets_the_push(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $gone = $this->pushDevice($this->portalUser);
        $phone = $this->pushDevice($this->portalUser);
        $this->push->expired = [$gone->endpoint];

        $this->book();

        $this->assertModelMissing($gone);
        $this->assertSame([$phone->endpoint], array_column($this->push->sent, 'endpoint'));
        $this->assertSame('2026-10-02T08:00:00Z', $phone->fresh()->last_used_at->toIso8601ZuluString());
        $this->assertSame([], $this->emails());
    }

    public function test_a_refused_push_keeps_the_device_for_the_next_one(): void
    {
        $this->hearBy(PortalNotificationChannel::Both);
        $device = $this->pushDevice($this->portalUser);
        $this->push->failing = [$device->endpoint];

        $this->book();

        $this->assertModelExists($device);
        $this->assertNull($device->fresh()->last_used_at);
        $this->assertSame([], $this->push->sent);
        $this->assertCount(1, $this->emails());

        $this->push->failing = [];
        $this->travelTo('2026-10-02 09:00:00');
        $this->book('2026-10-06 13:00');

        $this->assertCount(1, $this->push->sent);
        $this->assertSame('2026-10-02T09:00:00Z', $device->fresh()->last_used_at->toIso8601ZuluString());
    }

    public function test_push_alone_falls_back_to_email_when_no_device_takes_the_push(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);

        // The browser dropped the subscription: the push service says so only now.
        $dropped = $this->pushDevice($this->portalUser);
        $this->push->expired = [$dropped->endpoint];
        $this->book();

        $this->assertModelMissing($dropped);
        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());

        // The push service refused it this time.
        $refused = $this->pushDevice($this->portalUser);
        $this->push->failing = [$refused->endpoint];
        $this->book('2026-10-06 13:00');

        $this->assertModelExists($refused);
        $this->assertSame([
            'Booked: your appointment on Mon Oct 5, 2026, 9:00 AM',
            'Booked: your appointment on Tue Oct 6, 2026, 9:00 AM',
        ], $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_the_email_fallback_follows_what_was_queued_not_the_setting_by_then(): void
    {
        $appointment = $this->appointmentByThePractice();
        $notice = fn (bool $mailQueued) => tap(
            new ClientBookingConfirmed(ClientAppointmentFacts::read($appointment->fresh(), $this->practice)),
            fn (ClientBookingConfirmed $notice) => $notice->mailQueued = $mailQueued,
        );
        $channel = app(PortalPushChannel::class);

        // Queued as push alone; the client chose "both" since and signed out on the phone.
        $this->hearBy(PortalNotificationChannel::Both);
        $channel->send($this->portalUser->fresh(), $notice(false));
        $this->assertCount(1, $this->emails());

        // Queued with its email; the client chose push alone since, and the push is refused.
        $this->hearBy(PortalNotificationChannel::Push);
        $this->push->failing = [$this->pushDevice($this->portalUser)->endpoint];
        $channel->send($this->portalUser->fresh(), $notice(true));
        $this->assertCount(1, $this->emails());

        // Queued as push alone; the client switched push off since: no push, the email instead.
        $this->push->failing = [];
        $this->hearBy(PortalNotificationChannel::Email);
        $channel->send($this->portalUser->fresh(), $notice(false));
        $this->assertCount(2, $this->emails());
        $this->assertSame([], $this->push->sent);
    }

    public function test_today_and_tomorrow_become_the_date_when_the_push_could_arrive_after_midnight(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);
        $tomorrow = $this->appointmentByThePractice('2026-10-03 13:00'); // Sat 09:00 in New York

        // 14:00 in New York: a push held for its 12 hours could arrive after midnight.
        $this->travelTo('2026-10-02 18:00:00');
        $this->cancelByThePractice($tomorrow);

        $this->assertSame(['Sat, Oct 3, 2026 at 9:00 AM'], array_column($this->pushes(), 1));
    }

    public function test_pushes_that_would_run_past_the_time_allowed_are_left_for_the_email(): void
    {
        config(['portal.push.deliver_seconds' => 0]);
        $this->hearBy(PortalNotificationChannel::Push);
        $this->pushDevice($this->portalUser);

        $this->book();

        $this->assertSame([], $this->push->sent);
        $this->assertSame(['Booked: your appointment on Mon Oct 5, 2026, 9:00 AM'], $this->emails());
    }

    public function test_the_most_recently_reached_device_is_pushed_first(): void
    {
        $this->hearBy(PortalNotificationChannel::Push);
        $old = $this->pushDevice($this->portalUser);
        $recent = $this->pushDevice($this->portalUser);
        $new = $this->pushDevice($this->portalUser);
        $old->forceFill(['last_used_at' => now()->subDays(9)])->save();
        $recent->forceFill(['last_used_at' => now()->subHour()])->save();

        $this->book();

        $this->assertSame([$recent->endpoint, $old->endpoint, $new->endpoint], array_column($this->push->sent, 'endpoint'));
    }
}
