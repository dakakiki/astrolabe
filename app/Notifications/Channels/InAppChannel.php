<?php

namespace App\Notifications\Channels;

use App\Models\AppNotification;
use App\Models\User;
use App\Support\Notifications\InAppMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Notifications in the app (docs/spec/09, "Obaveštenja"): the notification's
 * `toInApp()` message as a row the bell in the header lists. Which kinds go
 * this way is the person's choice (NotificationPreferences::via).
 *
 * A practice deleted while the notice waited in the queue takes nothing new.
 */
final class InAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toInApp')) {
            return;
        }

        $message = $notification->toInApp($notifiable);

        if (! $message instanceof InAppMessage) {
            return;
        }

        if ($message->workspaceId !== null && ! DB::table('workspaces')->where('id', $message->workspaceId)->exists()) {
            return;
        }

        AppNotification::query()->create([
            'user_id' => $notifiable->getKey(),
            'workspace_id' => $message->workspaceId,
            'kind' => $message->kind,
            'data' => $message->data,
            'created_at' => now(),
        ]);
    }
}
