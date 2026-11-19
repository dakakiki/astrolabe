<?php

namespace App\Notifications;

use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\WorkspaceExport;
use App\Support\Notifications\InAppMessage;
use App\Support\Notifications\MailLook;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Your practice export is ready" (BuildPracticeExport). The button is a signed
 * link that works for a day and still needs the owner signed in; after that
 * the export is in Settings → Your data until it is deleted.
 */
class PracticeExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public readonly int $exportId;

    public readonly string $practice;

    public readonly string $expiresAt;

    public readonly ?int $workspaceId;

    public const PREFERENCE = 'export_ready';

    public function __construct(WorkspaceExport $export)
    {
        $this->exportId = $export->getKey();
        $this->practice = $export->workspace->name;
        $this->expiresAt = $export->expires_at->toIso8601ZuluString();
        $this->workspaceId = $export->workspace_id === null ? null : (int) $export->workspace_id;
    }

    /**
     * Email, in the app or both, as the person chose (Settings → Notifications).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? $notifiable->notificationPreferences()->via(self::PREFERENCE) : ['mail'];
    }

    /** Not for an export deleted in the meantime (a client deleted for good takes them along). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return WorkspaceExport::withoutGlobalScope(WorkspaceScope::class)->find($this->exportId)?->isDownloadable() === true;
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $hours = config('astrolabe.exports.link_hours');
        $link = URL::temporarySignedRoute('practice-exports.link', now()->addHours($hours), ['export' => $this->exportId]);
        $until = CarbonImmutable::parse($this->expiresAt)
            ->setTimezone($notifiable->timezone ?: 'UTC')
            ->locale(app()->getLocale())
            ->isoFormat('LL');

        return MailLook::forAstrologer((new MailMessage)
            ->subject(__('notifications.export_ready.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.export_ready.line', ['practice' => $this->practice]))
            ->action(__('notifications.export_ready.action'), $link)
            ->line(__('notifications.export_ready.link', ['hours' => $hours]))
            ->line(__('notifications.export_ready.kept', ['date' => $until]))
            ->line(__('notifications.export_ready.private')));
    }

    /** In the app: the export can be downloaded in Settings → Your data until it expires. */
    public function toInApp(User $notifiable): InAppMessage
    {
        return new InAppMessage(self::PREFERENCE, $this->workspaceId, ['export_id' => $this->exportId, 'expires_at' => $this->expiresAt]);
    }
}
