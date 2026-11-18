<?php

namespace Tests\Feature\Notifications;

use App\Actions\Booking\ConfirmAppointment;
use App\Actions\Booking\ConfirmMove;
use App\Actions\Booking\DeclineMove;
use App\Actions\Booking\RescheduleAppointment;
use App\Models\Appointment;
use App\Notifications\ClientAppointmentMoved;
use App\Notifications\ClientMoveRequest;
use App\Notifications\PortalBookingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Emails about a client's request to move a confirmed appointment (docs/spec/12,
 * "Pomeranje kao zahtev"): the astrologer is asked to confirm or decline it —
 * without the client's name — and the client hears that it was asked, then the
 * answer, with the practice's message when the move was declined.
 *
 * Friday 2 October 2026. Ana (New York) has a confirmed appointment on Monday
 * 5 October at 13:00 UTC: 3:00 PM in Belgrade, 9:00 AM for her. She asks for
 * Tuesday at 12:00 UTC: 2:00 PM in Belgrade, 8:00 AM for her.
 */
class MoveRequestEmailsTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        Notification::fake();
        $this->policy(['requires_confirmation' => true]);
        $this->appointment = $this->book();
        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($this->appointment));
    }

    private function ask(string $utcStart = '2026-10-06 12:00'): void
    {
        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser->fresh(),
            $this->appointment->fresh(),
            CarbonImmutable::parse($utcStart, 'UTC'),
        ));
    }

    public function test_the_astrologer_is_asked_and_the_client_told_it_waits(): void
    {
        $this->ask();

        Notification::assertSentTo($this->astrologer, function (PortalBookingNotice $notice) {
            if ($notice->kind !== PortalBookingNotice::MOVE_REQUESTED) {
                return false;
            }

            $mail = $notice->toMail($this->astrologer);
            $text = $this->mailText($mail);

            $this->assertSame('Move request: Tue Oct 6, 2026, 2:00 PM', $mail->subject);
            $this->assertStringContainsString('A client asked in your client portal to move their appointment from Monday, October 5, 2026, 3:00 PM – 4:00 PM to Tuesday, October 6, 2026, 2:00 PM – 3:00 PM (Europe/Belgrade).', $text);
            $this->assertStringContainsString('until you confirm or decline the move', $text);
            $this->assertSame('Confirm or decline', $mail->actionText);
            // The calendar opens where the appointment still is.
            $this->assertSame(url('/calendar').'?date=2026-10-05&appointment='.$this->appointment->id, $mail->actionUrl);
            $this->assertStringNotContainsString('Ana', $text);
            $this->assertStringNotContainsString('Viber', $text);

            return $notice->shouldSend($this->astrologer, 'mail');
        });

        Notification::assertSentTo($this->portalUser, function (ClientMoveRequest $email) {
            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertSame(ClientMoveRequest::REQUESTED, $email->kind);
            $this->assertSame('Move requested: your appointment on Tue Oct 6, 2026, 8:00 AM', $mail->subject);
            $this->assertStringContainsString('Vega Astrology received your request to move your appointment to Tuesday, October 6, 2026, 8:00 AM – 9:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('your appointment stays on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York)', $text);
            $this->assertStringNotContainsString('You can move', $text);

            return $email->shouldSend($this->portalUser, 'mail');
        });

        // An astrologer's email that waited for quiet hours is dropped once the request is answered.
        $this->inPractice(fn () => app(DeclineMove::class)->handle($this->appointment, null));
        Notification::assertSentTo($this->astrologer, fn (PortalBookingNotice $notice) => $notice->kind === PortalBookingNotice::MOVE_REQUESTED
            && ! $notice->shouldSend($this->astrologer, 'mail'));
    }

    public function test_the_client_hears_the_move_was_confirmed(): void
    {
        $this->ask();
        $this->inPractice(fn () => app(ConfirmMove::class)->handle($this->appointment));

        Notification::assertSentTo($this->portalUser, function (ClientMoveRequest $email) {
            if ($email->kind !== ClientMoveRequest::CONFIRMED) {
                return false;
            }

            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertSame('Moved: your appointment is now on Tue Oct 6, 2026, 8:00 AM', $mail->subject);
            $this->assertStringContainsString('Vega Astrology confirmed the new time: your appointment is now on Tuesday, October 6, 2026, 8:00 AM – 9:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('It was on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('You can move or cancel it in your portal until', $text);

            return true;
        });

        // Not also "moved by the practice", and the astrologer is not told what they did.
        Notification::assertNotSentTo($this->portalUser, ClientAppointmentMoved::class);
        Notification::assertSentToTimes($this->astrologer, PortalBookingNotice::class, 2);
    }

    public function test_the_client_hears_the_move_was_declined_with_the_practices_message(): void
    {
        $this->ask();
        $this->inPractice(fn () => app(DeclineMove::class)->handle($this->appointment, 'Tuesday is fully booked, sorry.'));

        Notification::assertSentTo($this->portalUser, function (ClientMoveRequest $email) {
            if ($email->kind !== ClientMoveRequest::DECLINED) {
                return false;
            }

            $mail = $email->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertSame('Not moved: your appointment stays on Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertStringContainsString('Vega Astrology could not move your appointment to Tuesday, October 6, 2026, 8:00 AM – 9:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('Your appointment stays on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('Message from Vega Astrology: Tuesday is fully booked, sorry.', $text);

            [$title, $body] = [$email->toPortalPush($this->portalUser)->title, $email->toPortalPush($this->portalUser)->body];
            $this->assertSame('Move not confirmed', $title);
            $this->assertStringNotContainsString('fully booked', $body);

            return true;
        });
    }
}
