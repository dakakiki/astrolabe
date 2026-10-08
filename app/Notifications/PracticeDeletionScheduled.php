<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To every member when the owner schedules the practice for deletion: the day
 * it goes, how to cancel, and what to do if nobody asked for it.
 */
class PracticeDeletionScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly string $practice,
        public readonly string $deletesAt,
    ) {}

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
        $date = CarbonImmutable::parse($this->deletesAt)
            ->setTimezone($notifiable->timezone ?: 'UTC')
            ->locale(app()->getLocale())
            ->isoFormat('LL');

        return (new MailMessage)
            ->subject(__('notifications.deletion_scheduled.subject', ['date' => $date]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.deletion_scheduled.line', ['practice' => $this->practice, 'date' => $date]))
            ->line(__('notifications.deletion_scheduled.cancel'))
            ->action(__('notifications.deletion_scheduled.action'), url('/practice-deletion'))
            ->line(__('notifications.deletion_scheduled.not_you'));
    }
}
