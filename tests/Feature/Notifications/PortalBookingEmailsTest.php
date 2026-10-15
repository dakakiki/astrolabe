<?php

namespace Tests\Feature\Notifications;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\SaveAppointment;
use App\Actions\Booking\CancelPortalAppointment;
use App\Actions\Booking\ConfirmAppointment;
use App\Actions\Booking\DeclineAppointment;
use App\Actions\Booking\RescheduleAppointment;
use App\Enums\AppointmentActor;
use App\Enums\ClientStatus;
use App\Enums\LocationType;
use App\Enums\PortalAccessStatus;
use App\Events\Appointments\AppointmentBooked;
use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Notifications\ClientAppointmentCancelled;
use App\Notifications\ClientAppointmentMoved;
use App\Notifications\ClientBookingConfirmed;
use App\Notifications\ClientBookingDeclined;
use App\Notifications\ClientBookingReceived;
use App\Notifications\PortalBookingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Emails about bookings in the client portal (docs/spec/12, "Mejlovi (9b)"):
 * the client hears about their bookings and every change — the practice's
 * only when it says so — on their own clock, naming the practice and nothing
 * private; the astrologer hears what the client did, without the client's
 * name or message, after their quiet hours, unless they switched it off.
 * Nobody hears anything from a closing practice, and a client who cannot use
 * the portal gets no email at all.
 */
class PortalBookingEmailsTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        Notification::fake();
    }

    public function test_a_booking_is_confirmed_to_the_client_on_their_clock(): void
    {
        $appointment = $this->book();

        Notification::assertSentToTimes($this->portalUser, ClientBookingReceived::class, 1);
        Notification::assertSentTo($this->portalUser, function (ClientBookingReceived $email) {
            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertFalse($email->isRequest());
            $this->assertSame('Booked: your appointment on Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertSame('Hello Ana,', $mail->greeting);
            $this->assertStringContainsString('Your appointment with Vega Astrology is booked for Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('Service: Natal reading', $text);
            $this->assertStringContainsString('Where: online', $text);
            $this->assertStringContainsString('You can move or cancel it in your portal until Sunday, October 4, 2026, 9:00 AM (America/New_York).', $text);
            $this->assertSame('http://portal.astrolabe.test/appointments', $mail->actionUrl);
            $this->assertStringNotContainsString('Viber', $text);
            $this->assertNull($email->delay);

            return $email->shouldSend($this->portalUser, 'mail');
        });

        // Until the client picks a zone, the practice's.
        $this->portalUser->forceFill(['timezone' => null])->save();
        Notification::assertSentTo($this->portalUser, function (ClientBookingReceived $email) {
            $text = $this->mailText($email->toMail($this->portalUser->fresh()));

            return str_contains($text, 'Monday, October 5, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade)');
        });

        $this->assertSame('America/New_York', $appointment->timezone);
    }

    public function test_the_astrologer_hears_about_a_booking_without_the_clients_name_or_message(): void
    {
        $appointment = $this->book();

        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 1);
        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) use ($appointment) {
            $mail = $notice->toMail($this->astrologer);
            $text = $this->mailText($mail);

            $this->assertSame(PortalBookingNotice::BOOKED, $notice->kind);
            $this->assertSame('New booking: Mon Oct 5, 2026, 3:00 PM', $mail->subject);
            $this->assertStringContainsString('A client booked an appointment in your client portal: Monday, October 5, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade).', $text);
            $this->assertStringContainsString('Service: Natal reading', $text);
            $this->assertSame(url('/calendar').'?date=2026-10-05&appointment='.$appointment->id, $mail->actionUrl);
            $this->assertStringNotContainsString('Ana', $text);
            $this->assertStringNotContainsString('Marković', $text);
            $this->assertStringNotContainsString('Viber', $text);
            $this->assertNull($notice->delay);

            return $notice->shouldSend($this->astrologer, 'mail');
        });
    }

    public function test_a_request_is_acknowledged_and_the_astrologer_is_asked_to_answer_it(): void
    {
        $this->policy(['requires_confirmation' => true]);

        $appointment = $this->book();

        Notification::assertSentTo($this->portalUser, function (ClientBookingReceived $email) {
            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertTrue($email->isRequest());
            $this->assertSame('Request received: your appointment on Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertStringContainsString('Vega Astrology received your request for an appointment on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('The time is held for you until Vega Astrology confirms it.', $text);

            return true;
        });

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) use ($appointment) {
            $mail = $notice->toMail($this->astrologer);

            $this->assertSame(PortalBookingNotice::REQUESTED, $notice->kind);
            $this->assertSame('Booking request: Mon Oct 5, 2026, 3:00 PM', $mail->subject);
            $this->assertStringContainsString('until you confirm or decline the request', $this->mailText($mail));
            $this->assertSame('Confirm or decline', $mail->actionText);
            $this->assertSame(url('/calendar').'?date=2026-10-05&appointment='.$appointment->id, $mail->actionUrl);

            return true;
        });
    }

    public function test_confirming_a_request_tells_the_client_and_settles_the_astrologers_question(): void
    {
        $this->policy(['requires_confirmation' => true]);
        $appointment = $this->book();

        $notice = PortalBookingNotice::booked($appointment);
        $this->assertTrue($notice->shouldSend($this->astrologer, 'mail'));

        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($appointment));

        Notification::assertSentToTimes($this->portalUser, ClientBookingConfirmed::class, 1);
        Notification::assertSentTo($this->portalUser, function (ClientBookingConfirmed $email) {
            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertSame('Confirmed: your appointment on Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertStringContainsString('Vega Astrology confirmed your appointment on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('You can move or cancel it in your portal until', $text);

            return true;
        });

        // The practice answered itself: no email back to it, and a request waiting
        // for the end of quiet hours is no longer worth sending.
        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 1);
        $this->assertFalse($notice->shouldSend($this->astrologer, 'mail'));
    }

    public function test_a_declined_request_carries_the_practices_message_to_the_client(): void
    {
        $this->policy(['requires_confirmation' => true]);
        $first = $this->book();
        $second = $this->book('2026-10-06 13:00');

        $this->inPractice(fn () => app(DeclineAppointment::class)->handle($first, 'That week is fully booked — the week after works.'));
        $this->inPractice(fn () => app(DeclineAppointment::class)->handle($second, null));

        Notification::assertSentToTimes($this->portalUser, ClientBookingDeclined::class, 2);
        Notification::assertSentTo($this->portalUser, function (ClientBookingDeclined $email) use ($first) {
            if ($email->appointment->appointmentId !== $first->id) {
                return false;
            }

            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertSame('Not accepted: your request for Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertStringContainsString('Vega Astrology could not accept your request for an appointment on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('Message from Vega Astrology: That week is fully booked — the week after works.', $text);
            $this->assertStringNotContainsString('You can move or cancel', $text);

            return true;
        });
        Notification::assertSentTo($this->portalUser, function (ClientBookingDeclined $email) use ($second) {
            return $email->appointment->appointmentId === $second->id
                && ! str_contains($this->mailText($email->toMail($this->portalUser)), 'Message from');
        });

        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 2);
    }

    public function test_a_move_in_the_portal_is_confirmed_to_the_client_and_announced_to_the_astrologer(): void
    {
        $appointment = $this->book();

        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $appointment,
            CarbonImmutable::parse('2026-10-06 14:00', 'UTC'),
        ));

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentMoved $email) {
            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertTrue($email->byClient);
            $this->assertSame('Moved: your appointment is now on Tue Oct 6, 2026, 10:00 AM', $mail->subject);
            $this->assertStringContainsString('As you asked, your appointment with Vega Astrology is now on Tuesday, October 6, 2026, 10:00 AM – 11:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('It was on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('until Monday, October 5, 2026, 10:00 AM (America/New_York).', $text);

            return true;
        });

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) use ($appointment) {
            if ($notice->kind !== PortalBookingNotice::MOVED) {
                return false;
            }

            $mail = $notice->toMail($this->astrologer);
            $text = $this->mailText($mail);

            $this->assertSame('Appointment moved to Tue Oct 6, 2026, 4:00 PM', $mail->subject);
            $this->assertStringContainsString('A client moved their appointment in your client portal from Monday, October 5, 2026, 3:00 PM – 4:00 PM to Tuesday, October 6, 2026, 4:00 PM – 5:00 PM (Europe/Belgrade).', $text);
            $this->assertSame(url('/calendar').'?date=2026-10-06&appointment='.$appointment->id, $mail->actionUrl);
            $this->assertStringNotContainsString('Ana', $text);

            return true;
        });
    }

    public function test_each_side_reads_the_times_on_its_own_clock_across_the_change_of_the_clocks(): void
    {
        $this->service->forceFill(['location_type' => LocationType::InPerson])->save();

        // Friday 23 October at 15:00 in Belgrade (summer time) → Monday 26 October
        // at 15:00 (winter time, from 25 October); New York changes only on 1 November.
        $appointment = $this->book('2026-10-23 13:00');
        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $appointment,
            CarbonImmutable::parse('2026-10-26 14:00', 'UTC'),
        ));

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentMoved $email) {
            $text = $this->mailText($email->toMail($this->portalUser));

            $this->assertStringContainsString('is now on Monday, October 26, 2026, 10:00 AM – 11:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('It was on Friday, October 23, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('Where: in person', $text);

            // On the practice's clock, both are at three in the afternoon.
            $text = $this->mailText($email->toMail($this->portalUser->replicate()->forceFill(['timezone' => null])));
            $this->assertStringContainsString('is now on Monday, October 26, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade).', $text);
            $this->assertStringContainsString('It was on Friday, October 23, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade).', $text);

            return true;
        });

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            return $notice->kind === PortalBookingNotice::MOVED && str_contains(
                $this->mailText($notice->toMail($this->astrologer)),
                'from Friday, October 23, 2026, 3:00 PM – 4:00 PM to Monday, October 26, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade).',
            );
        });

        // The client's reminder is a day before, by the clock of the world.
        $this->assertSame('2026-10-25T14:00:00Z', $appointment->fresh()->client_remind_at->toIso8601ZuluString());
    }

    public function test_a_cancellation_in_the_portal_is_confirmed_and_announced_without_the_clients_words(): void
    {
        $appointment = $this->book();

        $this->inPractice(fn () => app(CancelPortalAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $appointment,
            'Feeling unwell, sorry.',
        ));

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentCancelled $email) {
            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertTrue($email->byClient);
            $this->assertSame('Cancelled: your appointment on Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertStringContainsString('As you asked, your appointment with Vega Astrology on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York) is cancelled.', $text);
            $this->assertStringNotContainsString('You can move or cancel', $text);

            return true;
        });

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            if ($notice->kind !== PortalBookingNotice::CANCELLED) {
                return false;
            }

            $text = $this->mailText($notice->toMail($this->astrologer));

            $this->assertStringContainsString('A client cancelled their appointment in your client portal: Monday, October 5, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade).', $text);
            $this->assertStringNotContainsString('unwell', $text);
            $this->assertStringNotContainsString('Ana', $text);

            return $notice->shouldSend($this->astrologer, 'mail');
        });
    }

    public function test_the_practices_own_changes_reach_the_client_only_when_it_says_so(): void
    {
        $quiet = $this->appointmentByThePractice('2026-10-05 13:00');
        $told = $this->appointmentByThePractice('2026-10-07 13:00');

        $this->inPractice(fn () => app(SaveAppointment::class)->handle($quiet, ['starts_at' => '2026-10-06T15:00', 'timezone' => 'Europe/Belgrade'], notifyClient: false));
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($quiet->fresh(), 'Internal: double booked', notifyClient: false));
        Notification::assertNothingSent();

        $this->inPractice(fn () => app(SaveAppointment::class)->handle($told, ['starts_at' => '2026-10-08T16:00', 'timezone' => 'Europe/Belgrade']));

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentMoved $email) {
            $text = $this->mailText($email->toMail($this->portalUser));

            $this->assertFalse($email->byClient);
            $this->assertStringContainsString('Vega Astrology moved your appointment to Thursday, October 8, 2026, 10:00 AM – 11:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('It was on Wednesday, October 7, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringNotContainsString('Saturn', $text);

            return true;
        });

        $this->inPractice(fn () => app(CancelAppointment::class)->handle($told->fresh(), 'Internal: double booked'));

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentCancelled $email) {
            $text = $this->mailText($email->toMail($this->portalUser));

            $this->assertFalse($email->byClient);
            $this->assertStringContainsString('Vega Astrology cancelled your appointment on Thursday, October 8, 2026, 10:00 AM – 11:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('please contact Vega Astrology', $text);
            $this->assertStringNotContainsString('double booked', $text);
            $this->assertStringNotContainsString('Saturn', $text);

            return true;
        });

        // What the practice does is never announced back to it.
        Notification::assertNotSentTo($this->astrologer, PortalBookingNotice::class);
    }

    public function test_nothing_is_said_about_an_appointment_that_has_already_begun(): void
    {
        $past = $this->appointmentByThePractice('2026-10-01 13:00');

        $this->inPractice(fn () => app(SaveAppointment::class)->handle($past, ['starts_at' => '2026-10-01T16:00', 'timezone' => 'Europe/Belgrade']));
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($past->fresh(), null));

        Notification::assertNothingSent();
    }

    public function test_a_client_who_cannot_use_the_portal_gets_no_email(): void
    {
        $access = PortalAccess::query()->acrossPractices()->where('client_id', $this->client->id)->sole();

        // Without any portal account.
        $other = Client::factory()->inWorkspace($this->practice->id)->create();
        $appointment = Appointment::factory()->forClient($other, $this->astrologer)->at('2026-10-05 13:00')->create();
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($appointment, null));

        // Revoked.
        $access->forceFill(['status' => PortalAccessStatus::Revoked, 'revoked_at' => now()])->save();
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($this->appointmentByThePractice(), null));

        // Archived.
        $access->forceFill(['status' => PortalAccessStatus::Active, 'revoked_at' => null])->save();
        $this->client->forceFill(['status' => ClientStatus::Archived])->save();
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($this->appointmentByThePractice('2026-10-06 13:00'), null));

        Notification::assertNothingSent();
    }

    public function test_an_email_waiting_in_the_queue_is_dropped_once_the_portal_closes(): void
    {
        $this->book();

        $email = null;
        Notification::assertSentTo($this->portalUser, function (ClientBookingReceived $sent) use (&$email) {
            $email = $sent;

            return true;
        });
        $this->assertTrue($email->shouldSend($this->portalUser, 'mail'));

        PortalAccess::query()->acrossPractices()->where('client_id', $this->client->id)
            ->update(['status' => PortalAccessStatus::Revoked->value]);

        $this->assertFalse($email->shouldSend($this->portalUser, 'mail'));
    }

    public function test_a_closing_practice_sends_nothing(): void
    {
        $appointment = $this->book();
        Notification::fake();

        $this->practice->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(30)])->save();

        AppointmentBooked::dispatch($appointment);
        AppointmentRescheduled::dispatch($appointment, $appointment->starts_at->subDay(), $appointment->ends_at->subDay(), AppointmentActor::Client);
        AppointmentCancelled::dispatch($appointment, AppointmentActor::Client);
        AppointmentCancelled::dispatch($appointment, AppointmentActor::Practice);

        Notification::assertNothingSent();

        // An astrologer's email already waiting is dropped too.
        $this->assertFalse(PortalBookingNotice::booked($appointment)->shouldSend($this->astrologer, 'mail'));
    }

    public function test_the_astrologer_can_switch_the_emails_off(): void
    {
        $this->astrologer->forceFill(['notification_preferences' => ['portal_bookings' => false]])->save();

        $appointment = $this->book();

        Notification::assertSentTo($this->portalUser, ClientBookingReceived::class);
        Notification::assertNotSentTo($this->astrologer, PortalBookingNotice::class);

        // Switched off while one waited for the morning: it stays unsent.
        $this->assertFalse(PortalBookingNotice::booked($appointment)->shouldSend($this->astrologer->fresh(), 'mail'));
    }

    public function test_the_astrologers_email_waits_for_the_end_of_quiet_hours(): void
    {
        // 23:30 in Belgrade: quiet until 08:00.
        $this->travelTo('2026-10-02 21:30:00');

        $this->book();

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            $this->assertInstanceOf(CarbonImmutable::class, $notice->delay);
            $this->assertSame('2026-10-03T06:00:00Z', $notice->delay->toIso8601ZuluString());

            return true;
        });

        // The client's own email is not held back.
        Notification::assertSentTo($this->portalUser, fn (ClientBookingReceived $email) => $email->delay === null);

        // Quiet hours off: at once.
        $this->astrologer->forceFill(['notification_preferences' => ['quiet_hours' => null]])->save();
        Notification::fake();
        $this->book('2026-10-06 13:00');

        Notification::assertSentTo($this->astrologer, fn (PortalBookingNotice $notice) => $notice->delay === null);
    }

    public function test_when_the_appointment_starts_before_the_quiet_ends_the_email_goes_at_once(): void
    {
        // 23:30 in Belgrade; the client cancels an appointment at 07:00 tomorrow.
        $this->travelTo('2026-10-02 21:30:00');
        $early = $this->appointmentByThePractice('2026-10-03 05:00');

        AppointmentCancelled::dispatch($early, AppointmentActor::Client);

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            $this->assertSame(PortalBookingNotice::CANCELLED, $notice->kind);
            $this->assertNull($notice->delay);

            return true;
        });
    }

    public function test_a_move_away_from_a_time_inside_the_quiet_hours_is_announced_at_once(): void
    {
        // Moving is allowed until two hours before. At 23:00 in Belgrade the client moves
        // tomorrow's 07:00 to next Friday: waiting for 08:00 would leave the astrologer
        // expecting them at 07:00, so the email goes now — the old start is the deadline.
        $this->policy(['client_reschedule_until_hours' => 2]);
        $this->travelTo('2026-10-02 21:00:00');
        $early = $this->appointmentByThePractice('2026-10-03 05:00');

        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $early,
            CarbonImmutable::parse('2026-10-09 13:00', 'UTC'),
        ));

        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 1);
        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            $this->assertSame(PortalBookingNotice::MOVED, $notice->kind);
            $this->assertNull($notice->delay);

            return true;
        });

        // Both times well past the quiet hours: it waits for the morning as before.
        Notification::fake();
        $later = $this->appointmentByThePractice('2026-10-07 13:00');
        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $later,
            CarbonImmutable::parse('2026-10-08 13:00', 'UTC'),
        ));

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            $this->assertSame(PortalBookingNotice::MOVED, $notice->kind);
            $this->assertSame('2026-10-03T06:00:00Z', $notice->delay?->toIso8601ZuluString());

            return true;
        });
    }
}
