<?php

namespace App\Listeners;

use App\Enums\AppointmentActor;
use App\Events\Appointments\AppointmentBooked;
use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentMoveRequested;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PortalBookingNotice;
use Carbon\CarbonImmutable;

/**
 * Emails the astrologer about what a client did in the portal (docs/spec/12,
 * "Mejlovi (9b)"): a new booking or request, a move or a request to move, a cancellation — to the
 * person the appointment belongs to, by email, in the app or both as they chose
 * for that kind in Settings → Notifications. What the practice itself does is never announced
 * back to it, and a closing practice gets nothing.
 *
 * Inside the astrologer's quiet hours the email waits until they end — or goes
 * at once if they end only after the appointment starts (QuietHours::shift with
 * the start as the deadline; a moment not in the future means now). For a move
 * the deadline is the earlier of the old and the new start: the astrologer must
 * not wait at the old time for a client who moved away from it.
 */
class NotifyAstrologerOfPortalBooking
{
    public function handleBooked(AppointmentBooked $event): void
    {
        $this->send($event->appointment, PortalBookingNotice::booked($event->appointment));
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        if ($event->by !== AppointmentActor::Client) {
            return;
        }

        $previous = $event->previousStart;
        $start = $event->appointment->starts_at;

        $this->send(
            $event->appointment,
            PortalBookingNotice::moved($event->appointment, $event->previousStart, $event->previousEnd),
            $previous < $start ? $previous : $start,
        );
    }

    /**
     * A move asked for, not made: the deadline is the earlier of the two times,
     * since the answer decides where the astrologer is expected.
     */
    public function handleMoveRequested(AppointmentMoveRequested $event): void
    {
        $appointment = $event->appointment;
        $current = $appointment->starts_at;
        $proposed = $appointment->proposed_starts_at;

        if ($proposed === null) {
            return;
        }

        $this->send(
            $appointment,
            PortalBookingNotice::moveRequested($appointment),
            $current < $proposed ? $current : $proposed,
        );
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        if ($event->by !== AppointmentActor::Client) {
            return;
        }

        $this->send($event->appointment, PortalBookingNotice::cancelled($event->appointment));
    }

    /** `$deadline`: the moment the email must not wait past; the appointment's start unless given. */
    private function send(Appointment $appointment, PortalBookingNotice $notice, ?CarbonImmutable $deadline = null): void
    {
        $astrologer = $appointment->assigned_user_id === null ? null : User::query()->find($appointment->assigned_user_id);

        if ($astrologer === null
            || ! $astrologer->notificationPreferences()->wantsAny($notice->preference())
            || ! Workspace::query()->whereKey($appointment->workspace_id)->whereNull('deletes_at')->exists()) {
            return;
        }

        $at = self::sendAt($astrologer, $deadline ?? $appointment->starts_at, CarbonImmutable::now());

        // Quiet hours hold back the email; the bell in the app shows it at once.
        if ($at !== null) {
            $notice->delay(['mail' => $at]);
        }

        // The change is committed already: a failure to queue the email is reported, never the request's answer.
        rescue(fn () => $astrologer->notify($notice));
    }

    /** When the email may go out: null for now, else the end of the astrologer's quiet hours. */
    public static function sendAt(User $astrologer, CarbonImmutable $start, CarbonImmutable $now): ?CarbonImmutable
    {
        $moment = $astrologer->notificationPreferences()->quietHours?->shift($now, $astrologer->timezone ?: 'UTC', $start);

        return $moment !== null && $moment > $now ? $moment : null;
    }
}
