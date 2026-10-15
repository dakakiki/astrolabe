<?php

namespace App\Support\Portal\Push;

use App\Models\PortalPushSubscription;

/**
 * Records the pushes that would have gone, for tests (InteractsWithPortal::fakePush()).
 * An endpoint listed in `$expired` answers like a dropped subscription (410),
 * one in `$failing` like a push service that refused.
 */
final class FakePushSender implements PushSender
{
    /** @var list<array{subscription: int, endpoint: string, portal_user_id: int, message: PushMessage}> */
    public array $sent = [];

    /** @var list<string> */
    public array $expired = [];

    /** @var list<string> */
    public array $failing = [];

    public function send(PortalPushSubscription $subscription, PushMessage $message): PushResult
    {
        if (in_array($subscription->endpoint, $this->expired, true)) {
            return PushResult::Expired;
        }

        if (in_array($subscription->endpoint, $this->failing, true)) {
            return PushResult::Failed;
        }

        $this->sent[] = [
            'subscription' => (int) $subscription->getKey(),
            'endpoint' => $subscription->endpoint,
            'portal_user_id' => (int) $subscription->portal_user_id,
            'message' => $message,
        ];

        return PushResult::Sent;
    }

    /** @return list<PushMessage> */
    public function messagesFor(int $portalUserId): array
    {
        return array_values(array_map(
            fn (array $push) => $push['message'],
            array_filter($this->sent, fn (array $push) => $push['portal_user_id'] === $portalUserId),
        ));
    }
}
