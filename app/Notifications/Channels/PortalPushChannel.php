<?php

namespace App\Notifications\Channels;

use App\Models\PortalUser;
use App\Support\Notifications\ClientNotificationPreferences;
use App\Support\Portal\Push\PortalPush;
use App\Support\Portal\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * Web push to a client's devices (Phase 9c): the notification's
 * `toPortalPush()` message to every live subscription of the portal account.
 * Which notices go this way is the account's choice per notice (ClientChannels).
 *
 * When no device takes it — the browsers dropped their subscriptions, the
 * session ended while it waited in the queue, the push services refused — a
 * client who chose push alone gets the email instead, as with no device at
 * all: a notice about their appointment is never lost.
 *
 * The channels were picked when the notice was queued; the client may have
 * changed their choice since. A client who has switched push off for it by now
 * gets no push, and the email goes here unless one was queued beside the push
 * (`mailQueued`) — never twice; none only when they turned the notice off.
 */
final class PortalPushChannel
{
    public function __construct(private readonly PortalPush $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof PortalUser || ! method_exists($notification, 'toPortalPush')) {
            return;
        }

        $preferences = ClientNotificationPreferences::of($notifiable);
        $kind = method_exists($notification, 'preference') ? $notification->preference() : null;
        $wantsPush = $kind === null || $preferences->wants($kind, ClientNotificationPreferences::PUSH);
        $mailQueued = property_exists($notification, 'mailQueued') && $notification->mailQueued !== null
            ? $notification->mailQueued
            : ($kind !== null && $preferences->wants($kind, ClientNotificationPreferences::MAIL));

        $message = $wantsPush ? $notification->toPortalPush($notifiable) : null;
        $delivered = $message instanceof PushMessage ? $this->push->deliver($notifiable, $message) : 0;

        // Turned off altogether in the meantime: nothing; otherwise never lost.
        if ($delivered === 0 && ! $mailQueued && ($kind === null || $preferences->wantsAny($kind))) {
            $notifiable->notifyNow($notification, ['mail']);
        }
    }
}
