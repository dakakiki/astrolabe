<?php

namespace App\Notifications;

use App\Models\PortalUser;
use App\Support\Notifications\ClientAppointmentFacts;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the client when their appointment was cancelled (Phase 9b): a
 * confirmation when they cancelled it in the portal, or the news when the
 * practice cancelled it and chose to tell them. The practice's reason is its
 * own note and never goes to the client.
 */
class ClientAppointmentCancelled extends ClientAppointmentEmail
{
    public function __construct(
        ClientAppointmentFacts $appointment,
        public readonly bool $byClient,
    ) {
        parent::__construct($appointment);
    }

    /**
     * @param  PortalUser  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $this->zone($notifiable);
        $kind = $this->byClient ? 'cancelled_by_you' : 'cancelled';
        $values = ['practice' => $this->appointment->practice, 'when' => $this->when($zone)];

        $mail = $this->message($notifiable, __("portal.mail.booking.{$kind}.subject", ['when' => $this->shortWhen($zone)]))
            ->line(__("portal.mail.booking.{$kind}.line", $values))
            ->lines($this->details())
            ->line(__("portal.mail.booking.{$kind}.next", $values));

        return $this->withLink($mail);
    }
}
