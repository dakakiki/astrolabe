<?php

namespace App\Support\Portal\Push;

use App\Models\PortalPushSubscription;

/**
 * Hands one push to a browser's push service. WebPushSender in the
 * application (encrypted payload, VAPID); tests bind FakePushSender, which
 * only records what would have gone.
 */
interface PushSender
{
    public function send(PortalPushSubscription $subscription, PushMessage $message): PushResult;
}
