<?php

namespace App\Support\Notifications;

use App\Models\PortalUser;
use App\Notifications\Channels\PortalPushChannel;
use App\Support\Portal\Push\PortalPush;

/**
 * How one notice about an appointment reaches the client: their choice for that
 * notice in Portal → Profile → Notifications (ClientNotificationPreferences) —
 * email, push, both or none. Push needs a device that has it on right now
 * (PortalPush::reaches); a notice wanted by push with no such device comes by
 * email instead, so turning push on never loses one. When no device takes the
 * push after all (dropped, refused), PortalPushChannel sends the email itself.
 */
final class ClientChannels
{
    /**
     * @return list<string>
     */
    public static function for(PortalUser $user, string $kind): array
    {
        $preferences = ClientNotificationPreferences::of($user);
        $mail = $preferences->wants($kind, ClientNotificationPreferences::MAIL);
        $wantsPush = $preferences->wants($kind, ClientNotificationPreferences::PUSH);
        $push = $wantsPush && PortalPush::reaches($user);

        return array_values(array_filter([
            $mail || ($wantsPush && ! $push) ? 'mail' : null,
            $push ? PortalPushChannel::class : null,
        ]));
    }
}
