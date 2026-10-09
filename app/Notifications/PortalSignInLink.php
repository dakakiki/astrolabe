<?php

namespace App\Notifications;

use App\Models\PortalLoginToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A link and a six-digit code to sign in to the client portal (docs/spec/12,
 * "Prijava"). Queued, so asking for a link takes the same time whether the
 * address has an account or not. No practice name: one account may belong to
 * several.
 */
class PortalSignInLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $code,
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
        $minutes = config('portal.sign_in_minutes');

        return (new MailMessage)
            ->subject(__('portal.mail.sign_in.subject'))
            ->line(__('portal.mail.sign_in.intro'))
            ->action(__('portal.mail.sign_in.action'), PortalLoginToken::url($this->token))
            ->line(__('portal.mail.sign_in.code', ['code' => $this->code]))
            ->line(__('portal.mail.sign_in.expires', ['minutes' => $minutes]))
            ->line(__('portal.mail.sign_in.ignore'));
    }
}
