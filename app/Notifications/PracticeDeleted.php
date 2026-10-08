<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The last email, after the practice was deleted for good (DeletePractice). It
 * goes to an address, not an account: the account may be gone already.
 */
class PracticeDeleted extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $practice,
        public readonly bool $accountDeleted,
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
        $message = (new MailMessage)
            ->subject(__('notifications.deleted.subject'))
            ->greeting(__('notifications.deleted.greeting'))
            ->line(__('notifications.deleted.line', [
                'practice' => $this->practice,
                'days' => config('astrolabe.backup.keep'),
            ]));

        if ($this->accountDeleted) {
            $message->line(__('notifications.deleted.account'));
        }

        return $message->line(__('notifications.deleted.thanks'));
    }
}
