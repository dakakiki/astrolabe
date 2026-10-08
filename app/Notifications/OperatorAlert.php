<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short note to the operator (App\Support\Operations\OperatorAlerts).
 * Plain lines, no links into the application and nothing about clients.
 */
class OperatorAlert extends Notification
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $lines,
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
        $message = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message;
    }
}
