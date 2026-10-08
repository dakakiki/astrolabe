<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an astrologer what the operator did to their account (Phase 8c):
 * two-factor sign-in turned off, account suspended or restored — with the
 * reason the operator gave and where to ask.
 */
class AccountNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const TWO_FACTOR_RESET = 'two_factor_reset';

    public const SUSPENDED = 'suspended';

    public const RESTORED = 'restored';

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly string $kind,
        public readonly ?string $reason = null,
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
        $key = "admin.notice.{$this->kind}";
        $message = (new MailMessage)
            ->subject(__("{$key}.subject"))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__("{$key}.line"));

        if ($this->kind === self::TWO_FACTOR_RESET) {
            $message->line(__("{$key}.again"))->line(__("{$key}.not_you"));
        }

        if ($this->reason !== null && $this->reason !== '') {
            $message->line(__('admin.notice.reason', ['reason' => $this->reason]));
        }

        if ($this->kind === self::RESTORED) {
            $message->action(__("{$key}.action"), url('/login'));
        }

        if (filled(config('astrolabe.admin.support_email'))) {
            $message->line(__('admin.notice.support', ['email' => config('astrolabe.admin.support_email')]));
        }

        return $message;
    }
}
