<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Notifications\InAppMessage;
use App\Support\Notifications\MailLook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To every member when the owner cancels the practice's deletion. */
class PracticeDeletionCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public const PREFERENCE = 'practice_deletion_cancelled';

    public function __construct(
        public readonly string $practice,
        public readonly ?int $workspaceId = null,
    ) {}

    /**
     * Email, in the app or both, as the person chose (Settings → Notifications).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? $notifiable->notificationPreferences()->via(self::PREFERENCE) : ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return MailLook::forAstrologer((new MailMessage)
            ->subject(__('notifications.deletion_cancelled.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.deletion_cancelled.line', ['practice' => $this->practice]))
            ->action(__('notifications.deletion_cancelled.action'), url('/')));
    }

    public function toInApp(User $notifiable): InAppMessage
    {
        return new InAppMessage(self::PREFERENCE, $this->workspaceId);
    }
}
