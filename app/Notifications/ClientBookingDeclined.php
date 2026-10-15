<?php

namespace App\Notifications;

use App\Models\PortalUser;
use App\Support\Notifications\ClientAppointmentFacts;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the client when the practice turns down their booking request (Phase 9b),
 * with the practice's message when it wrote one — that text is written for the
 * client (DeclineAppointment), unlike the reason for a cancellation.
 */
class ClientBookingDeclined extends ClientAppointmentEmail
{
    public function __construct(
        ClientAppointmentFacts $appointment,
        public readonly ?string $practiceMessage = null,
    ) {
        parent::__construct($appointment);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function push(string $zone): array
    {
        // The practice's message stays in the portal and the email.
        return ['declined', __('portal.push.declined.body', ['when' => $this->pushWhen($zone)])];
    }

    /**
     * @param  PortalUser  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $this->zone($notifiable);

        $mail = $this->message($notifiable, __('portal.mail.booking.declined.subject', ['when' => $this->shortWhen($zone)]))
            ->line(__('portal.mail.booking.declined.line', [
                'practice' => $this->appointment->practice,
                'when' => $this->when($zone),
            ]))
            ->lines($this->details())
            ->lineIf(filled($this->practiceMessage), __('portal.mail.booking.declined.message', [
                'practice' => $this->appointment->practice,
                'message' => trim((string) $this->practiceMessage),
            ]))
            ->line(__('portal.mail.booking.declined.again', ['practice' => $this->appointment->practice]));

        return $this->withLink($mail);
    }
}
