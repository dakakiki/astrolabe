<?php

namespace App\Notifications;

use App\Models\PortalInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation to a practice's client portal (docs/spec/12, "Pozivnica").
 * Generic on purpose: the practice, who invites, the button, the week it works
 * — nothing about consultations.
 */
class PortalInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $practice,
        private readonly string $invitedBy,
        private readonly string $token,
        private readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('portal.mail.invitation.subject', ['practice' => $this->practice]))
            ->greeting(__('portal.mail.invitation.greeting'))
            ->line(__('portal.mail.invitation.intro', ['practice' => $this->practice, 'name' => $this->invitedBy]))
            ->line(__('portal.mail.invitation.what'))
            ->action(__('portal.mail.invitation.action'), PortalInvitation::url($this->token))
            ->line(__('portal.mail.invitation.expires', ['date' => $this->expiresAt->utc()->isoFormat('LL').' (UTC)']))
            ->line(__('portal.mail.invitation.ignore'));
    }
}
