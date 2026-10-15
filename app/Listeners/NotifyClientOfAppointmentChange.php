<?php

namespace App\Listeners;

use App\Enums\AppointmentActor;
use App\Events\Appointments\AppointmentBooked;
use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentConfirmed;
use App\Events\Appointments\AppointmentDeclined;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\Appointment;
use App\Notifications\ClientAppointmentCancelled;
use App\Notifications\ClientAppointmentEmail;
use App\Notifications\ClientAppointmentMoved;
use App\Notifications\ClientBookingConfirmed;
use App\Notifications\ClientBookingDeclined;
use App\Notifications\ClientBookingReceived;
use App\Support\Notifications\ClientAppointmentFacts;
use App\Support\Notifications\ClientRecipient;
use Closure;

/**
 * Emails the client about their appointment (docs/spec/12, "Mejlovi (9b)"):
 * booked or request received, confirmed, declined (with the practice's
 * message), moved and cancelled — a confirmation of what they did in the
 * portal, or the news of what the practice did when it chose to tell them.
 *
 * Only while the client can use the portal (ClientRecipient): no link, a
 * revoked one, an archived client or a closing practice sends nothing. The
 * events fire after the commit, so nothing goes out for a change rolled back.
 */
class NotifyClientOfAppointmentChange
{
    public function handleBooked(AppointmentBooked $event): void
    {
        $this->send($event->appointment, fn (ClientAppointmentFacts $facts) => new ClientBookingReceived($facts));
    }

    public function handleConfirmed(AppointmentConfirmed $event): void
    {
        $this->send($event->appointment, fn (ClientAppointmentFacts $facts) => new ClientBookingConfirmed($facts));
    }

    public function handleDeclined(AppointmentDeclined $event): void
    {
        $message = $event->appointment->cancellation_reason;

        $this->send($event->appointment, fn (ClientAppointmentFacts $facts) => new ClientBookingDeclined($facts, $message));
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $appointment = $event->appointment;
        $byClient = $event->by === AppointmentActor::Client;

        // The practice's moves reach the client when it says so, and only for a
        // time still ahead — correcting a past or closed appointment is its own business.
        if (! $byClient && (! $event->notifyClient || ! $appointment->status->isOpen() || ! $appointment->starts_at->isFuture())) {
            return;
        }

        $this->send($appointment, fn (ClientAppointmentFacts $facts) => new ClientAppointmentMoved(
            $facts,
            $event->previousStart->toIso8601ZuluString(),
            $event->previousEnd->toIso8601ZuluString(),
            $byClient,
        ));
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        $appointment = $event->appointment;
        $byClient = $event->by === AppointmentActor::Client;

        // An appointment that has already begun is not news to the client.
        if (! $byClient && (! $event->notifyClient || ! $appointment->starts_at->isFuture())) {
            return;
        }

        $this->send($appointment, fn (ClientAppointmentFacts $facts) => new ClientAppointmentCancelled($facts, $byClient));
    }

    /**
     * @param  Closure(ClientAppointmentFacts): ClientAppointmentEmail  $email
     */
    private function send(Appointment $appointment, Closure $email): void
    {
        $access = ClientRecipient::access((int) $appointment->workspace_id, (int) $appointment->client_id);

        if ($access === null || $access->portalUser === null) {
            return;
        }

        // The change is committed already: a failure to queue the email is reported, never the request's answer.
        rescue(fn () => $access->portalUser->notify($email(ClientAppointmentFacts::read($appointment, $access->workspace))));
    }
}
