<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The first email of a new admin account (`admin:create`, Phase 8c): a link to
 * set its password. Sent at once, not queued — the command prints the link too.
 */
class AdminWelcome extends Notification
{
    use Queueable;

    public function __construct(public readonly string $link) {}

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
            ->subject(__('admin.create.subject'))
            ->line(__('admin.create.line', ['minutes' => config('auth.passwords.users.expire', 60)]))
            ->action(__('admin.create.action'), $this->link)
            ->line(__('admin.create.two_factor'));
    }
}
