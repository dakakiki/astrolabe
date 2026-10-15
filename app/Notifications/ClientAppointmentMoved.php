<?php

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\PortalUser;
use App\Support\Notifications\ClientAppointmentFacts;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the client when their appointment moved (Phase 9b): a confirmation when
 * they moved it in the portal, or the news when the practice moved it and
 * chose to tell them. The new time first, the old one after it.
 */
class ClientAppointmentMoved extends ClientAppointmentEmail
{
    public function __construct(
        ClientAppointmentFacts $appointment,
        public readonly string $previousStartsAt,
        public readonly string $previousEndsAt,
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
        $kind = $this->byClient ? 'moved_by_you' : 'moved';
        $values = ['practice' => $this->appointment->practice, 'when' => $this->when($zone)];

        $mail = $this->message($notifiable, __("portal.mail.booking.{$kind}.subject", ['when' => $this->shortWhen($zone)]))
            ->line(__("portal.mail.booking.{$kind}.line", $values))
            ->line(__('portal.mail.booking.was', ['when' => $this->when($zone, $this->previousStartsAt, $this->previousEndsAt)]))
            ->lines($this->details())
            ->lineIf($this->appointment->status === AppointmentStatus::Requested->value, __('portal.mail.booking.pending', $values))
            ->lines($this->changeUntil($zone));

        return $this->withLink($mail);
    }
}
