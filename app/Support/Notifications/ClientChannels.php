<?php

namespace App\Support\Notifications;

use App\Enums\PortalNotificationChannel;
use App\Models\PortalUser;
use App\Notifications\Channels\PortalPushChannel;
use App\Support\Portal\Push\PortalPush;

/**
 * How a notice about an appointment reaches the client (Phase 9c): the
 * account's choice in Portal → Profile — email, push or both. Push needs a
 * device that has it on right now (PortalPush::reaches); without one the
 * notice goes by email whatever was chosen, so the client never misses a
 * booking change. When no device takes the push after all (dropped, refused),
 * PortalPushChannel sends the email itself.
 */
final class ClientChannels
{
    /**
     * @return list<string>
     */
    public static function for(PortalUser $user): array
    {
        $choice = $user->notification_channel ?? PortalNotificationChannel::Email;
        $push = $choice->wantsPush() && PortalPush::reaches($user);

        return array_values(array_filter([
            $choice->wantsEmail() || ! $push ? 'mail' : null,
            $push ? PortalPushChannel::class : null,
        ]));
    }
}
