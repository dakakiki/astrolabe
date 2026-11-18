<?php

namespace App\Notifications;

use App\Models\PortalUser;
use App\Support\Notifications\ClientAppointmentFacts;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the client about their request to move a confirmed appointment, while the
 * practice confirms bookings by hand (docs/spec/12, "Pomeranje kao zahtev"):
 *
 * - `requested`: received — it stays at its time until the practice answers,
 *   the new time (`otherStartsAt`) is held meanwhile;
 * - `confirmed`: the appointment is at the new time now (`otherStartsAt` was the old one);
 * - `declined`: it stays at its time (`otherStartsAt` was the one asked for),
 *   with the practice's message when it wrote one — written for the client.
 *
 * The facts are the appointment as it stands after the change.
 */
class ClientMoveRequest extends ClientAppointmentEmail
{
    public const REQUESTED = 'requested';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    public function __construct(
        ClientAppointmentFacts $appointment,
        public readonly string $kind,
        public readonly string $otherStartsAt,
        public readonly string $otherEndsAt,
        public readonly ?string $practiceMessage = null,
    ) {
        parent::__construct($appointment);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function push(string $zone): array
    {
        return match ($this->kind) {
            self::REQUESTED => ['move_requested', __('portal.push.move_requested.body', ['when' => $this->pushWhen($zone, $this->otherStartsAt)])],
            self::CONFIRMED => ['move_confirmed', $this->pushWhen($zone)],
            default => ['move_declined', __('portal.push.move_declined.body', ['when' => $this->pushWhen($zone)])],
        };
    }

    /**
     * @param  PortalUser  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $this->zone($notifiable);
        $other = $this->when($zone, $this->otherStartsAt, $this->otherEndsAt);
        $values = ['practice' => $this->appointment->practice, 'when' => $this->when($zone), 'other' => $other];
        $key = "portal.mail.booking.move_{$this->kind}";
        // The subject names the time that matters now: the one asked for, or where it stands.
        $subjectWhen = $this->kind === self::REQUESTED ? $this->shortWhen($zone, $this->otherStartsAt) : $this->shortWhen($zone);

        $mail = $this->message($notifiable, __("{$key}.subject", ['when' => $subjectWhen]))
            ->line(__("{$key}.line", $values))
            ->line(__("{$key}.next", $values))
            ->lines($this->details());

        if ($this->kind === self::DECLINED) {
            $mail->lineIf(filled($this->practiceMessage), __('portal.mail.booking.declined.message', [
                'practice' => $this->appointment->practice,
                'message' => trim((string) $this->practiceMessage),
            ]));
        }

        if ($this->kind !== self::REQUESTED) {
            $mail->lines($this->changeUntil($zone));
        }

        return $this->withLink($mail);
    }
}
