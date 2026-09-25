<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Send a test email" in Settings → Notifications. It takes the same road as
 * reminders — the queue, the worker and the mail server — so it shows whether
 * that road works on a new server.
 */
class TestEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $requestedAt) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $notifiable->timezone ?: 'UTC';
        $at = CarbonImmutable::parse($this->requestedAt)->setTimezone($zone)->locale(app()->getLocale());

        return (new MailMessage)
            ->subject(__('notifications.test.subject', ['app' => config('app.name')]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.test.line', ['time' => $at->isoFormat('LLLL'), 'zone' => $zone]))
            ->action(__('notifications.test.action'), url('/settings/notifications'));
    }
}
