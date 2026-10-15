<?php

namespace App\Notifications;

use App\Models\PortalUser;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the client when the practice confirms their booking request (Phase 9b).
 */
class ClientBookingConfirmed extends ClientAppointmentEmail
{
    /**
     * @return array{0: string, 1: string}
     */
    protected function push(string $zone): array
    {
        return ['confirmed', $this->pushWhen($zone)];
    }

    /**
     * @param  PortalUser  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $this->zone($notifiable);

        $mail = $this->message($notifiable, __('portal.mail.booking.confirmed.subject', ['when' => $this->shortWhen($zone)]))
            ->line(__('portal.mail.booking.confirmed.line', [
                'practice' => $this->appointment->practice,
                'when' => $this->when($zone),
            ]))
            ->lines($this->details())
            ->lines($this->changeUntil($zone));

        return $this->withLink($mail);
    }
}
