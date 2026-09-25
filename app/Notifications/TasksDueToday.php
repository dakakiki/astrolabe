<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The morning email about tasks (TaskDigest): how many are due today and how
 * many are overdue, in one practice, and a link. No titles — a task often
 * names its client ("Follow up with …").
 */
class TasksDueToday extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $practice,
        public readonly int $today,
        public readonly int $overdue,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

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
        $message = (new MailMessage)
            ->subject(trans_choice('notifications.digest.subject', $this->today, ['count' => $this->today]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(trans_choice('notifications.digest.today', $this->today, [
                'count' => $this->today,
                'practice' => $this->practice,
            ]));

        if ($this->overdue > 0) {
            $message->line(trans_choice('notifications.digest.overdue', $this->overdue, ['count' => $this->overdue]));
        }

        return $message
            ->action(__('notifications.digest.action'), url('/tasks').'?'.http_build_query(['show' => 'today']))
            ->line(__('notifications.digest.private'))
            ->line(__('notifications.settings', ['url' => url('/settings/notifications')]));
    }
}
