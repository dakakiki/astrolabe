<?php

namespace Tests\Feature\Notifications;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\SaveAppointment;
use App\Actions\Booking\ConfirmAppointment;
use App\Actions\Booking\RescheduleAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Notifications\ClientAppointmentReminder;
use App\Support\Notifications\ClientReminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The client's reminder a day before an appointment (Phase 9b, docs/spec/12):
 * planned with the appointment, moved with it, sent once by
 * `notifications:send-client-reminders` — only to a client who can use the
 * portal and has not switched reminders off in Profile.
 */
class ClientRemindersTest extends TestCase
{
    use BooksInThePortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPractice();
        Notification::fake();
    }

    private function sendDue(): void
    {
        $this->artisan('notifications:send-client-reminders')->assertSuccessful();
    }

    private function access(): PortalAccess
    {
        return PortalAccess::query()->acrossPractices()->where('client_id', $this->client->id)->sole();
    }

    public function test_appointments_saved_before_there_were_client_reminders_get_one(): void
    {
        // As the Phase 9b migration finds them (it is Friday 2 October, 08:00 UTC): nothing planned.
        $ahead = $this->appointmentByThePractice('2026-10-05 13:00');
        $tomorrow = $this->appointmentByThePractice('2026-10-03 05:00');
        $cancelled = $this->appointmentByThePractice('2026-10-06 13:00');
        $requested = $this->appointmentByThePractice('2026-10-07 13:00');
        $deleted = $this->appointmentByThePractice('2026-10-08 13:00');
        $planned = $this->appointmentByThePractice('2026-10-09 13:00');
        DB::table('appointments')->where('id', $cancelled->id)->update(['status' => AppointmentStatus::Cancelled->value]);
        DB::table('appointments')->where('id', $requested->id)->update(['status' => AppointmentStatus::Requested->value]);
        DB::table('appointments')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        DB::table('appointments')->whereIn('id', [$ahead->id, $tomorrow->id, $cancelled->id, $requested->id, $deleted->id])->update(['client_remind_at' => null]);
        DB::table('appointments')->where('id', $planned->id)->update(['client_remind_at' => '2026-10-08 20:00:00']);

        $this->assertSame(1, ClientReminders::backfill(CarbonImmutable::now()));

        // A day before, as for an appointment saved now; less than a day ahead, closed or
        // deleted: none; one already planned is left as it is.
        $remindAt = fn (Appointment $appointment) => DB::table('appointments')->where('id', $appointment->id)->value('client_remind_at');
        $this->assertSame('2026-10-04 13:00:00', $remindAt($ahead));
        foreach ([$tomorrow, $cancelled, $requested, $deleted] as $none) {
            $this->assertNull($remindAt($none));
        }
        $this->assertSame('2026-10-08 20:00:00', $remindAt($planned));
        $this->assertNull($ahead->fresh()->client_reminder_sent_at);

        // Run again, nothing changes; when it is due the client hears about it.
        $this->assertSame(0, ClientReminders::backfill(CarbonImmutable::now()));
        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDue();

        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 1);
        Notification::assertSentTo($this->portalUser, fn (ClientAppointmentReminder $reminder) => $reminder->appointment->appointmentId === $ahead->id);
    }

    public function test_the_reminder_is_planned_a_day_before_and_sent_once(): void
    {
        $appointment = $this->book();

        $this->assertSame('2026-10-04T13:00:00Z', $appointment->fresh()->client_remind_at->toIso8601ZuluString());
        $this->assertNull($appointment->fresh()->client_reminder_sent_at);

        $this->sendDue();
        Notification::assertNotSentTo($this->portalUser, ClientAppointmentReminder::class);

        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDue();
        $this->sendDue();

        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 1);
        $this->assertSame('2026-10-04T13:00:30Z', $appointment->fresh()->client_reminder_sent_at->toIso8601ZuluString());

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentReminder $reminder) {
            $mail = $reminder->toMail($this->portalUser);
            $text = $this->mailText($mail);

            $this->assertSame('Reminder: your appointment on Mon Oct 5, 2026, 9:00 AM', $mail->subject);
            $this->assertSame('Hello Ana,', $mail->greeting);
            $this->assertStringContainsString('This is a reminder of your appointment with Vega Astrology on Monday, October 5, 2026, 9:00 AM – 10:00 AM (America/New_York).', $text);
            $this->assertStringContainsString('Service: Natal reading', $text);
            $this->assertStringContainsString('switch it off in your portal, under Profile', $text);
            // The change deadline is now: nothing to offer.
            $this->assertStringNotContainsString('You can move or cancel', $text);
            $this->assertStringNotContainsString('Viber', $text);
            $this->assertSame('http://portal.astrolabe.test/appointments', $mail->actionUrl);

            return $reminder->shouldSend($this->portalUser, 'mail');
        });
    }

    public function test_appointments_the_astrologer_made_are_reminded_too_but_only_to_clients_with_the_portal(): void
    {
        $this->policy(['enabled' => false]);
        $mine = $this->appointmentByThePractice('2026-10-05 13:00');

        $other = Client::factory()->inWorkspace($this->practice->id)->create();
        $theirs = Appointment::factory()->forClient($other, $this->astrologer)->at('2026-10-05 15:00')->create();

        $this->travelTo('2026-10-04 15:00:00');
        $this->sendDue();

        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 1);
        Notification::assertCount(1);
        Notification::assertSentTo($this->portalUser, function (ClientAppointmentReminder $reminder) use ($mine) {
            $text = $this->mailText($reminder->toMail($this->portalUser));

            $this->assertSame($mine->id, $reminder->appointment->appointmentId);
            // Booking is off: the portal changes nothing, so no deadline is offered.
            $this->assertStringNotContainsString('until', $text);
            $this->assertStringNotContainsString('Saturn', $text);

            return true;
        });

        // Due and claimed either way, so it is not looked at again.
        $this->assertNotNull($theirs->fresh()->client_reminder_sent_at);
    }

    public function test_a_later_deadline_is_offered_in_the_reminder(): void
    {
        $this->policy(['client_reschedule_until_hours' => 12, 'client_cancel_until_hours' => 6]);
        $this->book();

        $this->travelTo('2026-10-04 13:01:00');
        $this->sendDue();

        Notification::assertSentTo($this->portalUser, function (ClientAppointmentReminder $reminder) {
            $text = $this->mailText($reminder->toMail($this->portalUser));

            $this->assertStringContainsString('You can move it in your portal until Sunday, October 4, 2026, 9:00 PM (America/New_York).', $text);
            $this->assertStringContainsString('You can cancel it in your portal until Monday, October 5, 2026, 3:00 AM (America/New_York).', $text);

            return true;
        });
    }

    public function test_no_reminder_when_the_client_switched_them_off_or_cannot_use_the_portal(): void
    {
        $first = $this->book('2026-10-05 13:00');
        $second = $this->book('2026-10-06 13:00');
        $third = $this->book('2026-10-07 13:00');

        // Switched off in Profile.
        $this->portalUser->forceFill(['appointment_reminders' => false])->save();
        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDue();
        $this->assertNotNull($first->fresh()->client_reminder_sent_at);

        // Back on, but the link was revoked.
        $this->portalUser->forceFill(['appointment_reminders' => true])->save();
        $this->access()->forceFill(['status' => PortalAccessStatus::Revoked, 'revoked_at' => now()])->save();
        $this->travelTo('2026-10-05 13:00:30');
        $this->sendDue();
        $this->assertNotNull($second->fresh()->client_reminder_sent_at);

        // Open again, but the client is archived.
        $this->access()->forceFill(['status' => PortalAccessStatus::Active, 'revoked_at' => null])->save();
        $this->client->forceFill(['status' => ClientStatus::Archived])->save();
        $this->travelTo('2026-10-06 13:00:30');
        $this->sendDue();
        $this->assertNotNull($third->fresh()->client_reminder_sent_at);

        Notification::assertNotSentTo($this->portalUser, ClientAppointmentReminder::class);
    }

    public function test_a_closing_practice_sends_no_reminders(): void
    {
        $appointment = $this->book();
        Notification::fake();
        $this->practice->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(30)])->save();

        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDue();

        Notification::assertNothingSent();
        $this->assertNull($appointment->fresh()->client_reminder_sent_at);
    }

    public function test_a_request_is_reminded_only_once_it_is_confirmed(): void
    {
        $this->policy(['requires_confirmation' => true]);
        $appointment = $this->book();

        $this->assertNull($appointment->fresh()->client_remind_at);

        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($appointment));
        $this->assertSame('2026-10-04T13:00:00Z', $appointment->fresh()->client_remind_at->toIso8601ZuluString());

        // A request confirmed less than a day before gets none.
        $late = $this->book('2026-10-06 13:00');
        $this->travelTo('2026-10-05 14:00:00');
        $this->inPractice(fn () => app(ConfirmAppointment::class)->handle($late));
        $this->assertNull($late->fresh()->client_remind_at);

        $this->sendDue();
        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 0);
    }

    public function test_a_booking_less_than_a_day_ahead_gets_no_reminder(): void
    {
        $this->policy(['min_notice_minutes' => 60]);

        // 15:00 in Belgrade today, five hours from now.
        $appointment = $this->book('2026-10-02 13:00');

        $this->assertNull($appointment->fresh()->client_remind_at);

        $this->travelTo('2026-10-02 12:30:00');
        $this->sendDue();
        Notification::assertNotSentTo($this->portalUser, ClientAppointmentReminder::class);
    }

    public function test_moving_plans_the_reminder_again_and_cancelling_stops_it(): void
    {
        $appointment = $this->book();

        $this->inPractice(fn () => app(RescheduleAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser,
            $appointment,
            CarbonImmutable::parse('2026-10-06 14:00', 'UTC'),
        ));
        $this->assertSame('2026-10-05T14:00:00Z', $appointment->fresh()->client_remind_at->toIso8601ZuluString());

        // Sent, then moved by the practice to a later day: reminded again.
        $this->travelTo('2026-10-05 14:00:30');
        $this->sendDue();
        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 1);

        $this->inPractice(fn () => app(SaveAppointment::class)->handle($appointment->fresh(), [
            'starts_at' => '2026-10-09T16:00',
            'timezone' => 'Europe/Belgrade',
        ]));
        $moved = $appointment->fresh();
        $this->assertSame('2026-10-08T14:00:00Z', $moved->client_remind_at->toIso8601ZuluString());
        $this->assertNull($moved->client_reminder_sent_at);

        $this->travelTo('2026-10-08 14:00:30');
        $this->sendDue();
        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 2);

        // Cancelled before its time: none.
        $other = $this->appointmentByThePractice('2026-10-12 13:00');
        $this->inPractice(fn () => app(CancelAppointment::class)->handle($other, null, notifyClient: false));
        $this->assertNull($other->fresh()->client_remind_at);

        $this->travelTo('2026-10-11 13:00:30');
        $this->sendDue();
        Notification::assertSentToTimes($this->portalUser, ClientAppointmentReminder::class, 2);
    }

    public function test_a_reminder_waiting_in_the_queue_is_dropped_when_things_change(): void
    {
        $appointment = $this->book();

        $this->travelTo('2026-10-04 13:00:30');
        $this->sendDue();

        $reminder = null;
        Notification::assertSentTo($this->portalUser, function (ClientAppointmentReminder $sent) use (&$reminder) {
            $reminder = $sent;

            return true;
        });

        $this->assertTrue($reminder->shouldSend($this->portalUser, 'mail'));

        // Switched off meanwhile.
        $this->portalUser->forceFill(['appointment_reminders' => false])->save();
        $this->assertFalse($reminder->shouldSend($this->portalUser, 'mail'));
        $this->portalUser->forceFill(['appointment_reminders' => true])->save();

        // The portal closed meanwhile.
        $this->access()->forceFill(['status' => PortalAccessStatus::Revoked])->save();
        $this->assertFalse($reminder->shouldSend($this->portalUser, 'mail'));
        $this->access()->forceFill(['status' => PortalAccessStatus::Active])->save();
        $this->assertTrue($reminder->shouldSend($this->portalUser, 'mail'));

        // The queue got to it only after the appointment began.
        $this->travelTo('2026-10-05 13:00:00');
        $this->assertFalse($reminder->shouldSend($this->portalUser, 'mail'));
        $this->travelTo('2026-10-04 13:01:00');

        // Moved meanwhile.
        $this->inPractice(fn () => app(SaveAppointment::class)->handle($appointment->fresh(), [
            'starts_at' => '2026-10-05T17:00',
            'timezone' => 'Europe/Belgrade',
        ], notifyClient: false));
        $this->assertFalse($reminder->shouldSend($this->portalUser, 'mail'));
    }
}
