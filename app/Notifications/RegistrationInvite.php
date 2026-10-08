<?php

namespace App\Notifications;

use App\Models\RegistrationInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The closed-beta invitation (`php artisan invitations:send`). Sent at once,
 * not through the queue, so the command can say whether it went out.
 */
class RegistrationInvite extends Notification
{
    public function __construct(
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
        $app = config('app.name');

        return (new MailMessage)
            ->subject(__('registration.mail.subject', ['app' => $app]))
            ->line(__('registration.mail.intro', ['app' => $app]))
            ->action(__('registration.mail.action'), RegistrationInvitation::url($this->token))
            ->line(__('registration.mail.expires', ['date' => $this->expiresAt->utc()->isoFormat('LL').' (UTC)']))
            ->line(__('registration.mail.ignore'));
    }
}
