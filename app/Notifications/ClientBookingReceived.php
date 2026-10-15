<?php

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\PortalUser;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the client after booking in the portal (Phase 9b): "your appointment is
 * booked", or — while the practice confirms bookings by hand — "your request
 * was received", with the time held until the practice answers.
 */
class ClientBookingReceived extends ClientAppointmentEmail
{
    public function isRequest(): bool
    {
        return $this->appointment->status === AppointmentStatus::Requested->value;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function push(string $zone): array
    {
        return $this->isRequest()
            ? ['requested', __('portal.push.requested.body', ['when' => $this->pushWhen($zone)])]
            : ['booked', $this->pushWhen($zone)];
    }

    /**
     * @param  PortalUser  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $this->zone($notifiable);
        $kind = $this->isRequest() ? 'requested' : 'booked';
        $values = ['practice' => $this->appointment->practice, 'when' => $this->when($zone)];

        $mail = $this->message($notifiable, __("portal.mail.booking.{$kind}.subject", ['when' => $this->shortWhen($zone)]))
            ->line(__("portal.mail.booking.{$kind}.line", $values))
            ->lines($this->details())
            ->lineIf($this->isRequest(), __('portal.mail.booking.requested.next', $values))
            ->lines($this->changeUntil($zone));

        return $this->withLink($mail);
    }
}
