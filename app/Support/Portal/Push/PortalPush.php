<?php

namespace App\Support\Portal\Push;

use App\Models\PortalPushSubscription;
use App\Models\PortalUser;

/**
 * Web push to clients in the portal PWA (Phase 9c, docs/spec/12, "Web push").
 *
 * - Off unless both VAPID keys are set (`configured()`); the portal then offers
 *   no push and every notice goes by email.
 * - A push goes only to the account's live subscriptions (PortalPushSubscription::
 *   scopeLive — the portal session they were made in still exists). A dropped
 *   subscription (404 / 410) is deleted; a refused push is only logged.
 * - Endpoints must be HTTPS addresses of the known push services
 *   (`allowsEndpoint()`), so the server never posts to an address a client made up.
 */
final class PortalPush
{
    public function __construct(private readonly PushSender $sender) {}

    public static function configured(): bool
    {
        return filled(config('portal.push.public_key')) && filled(config('portal.push.private_key'));
    }

    public static function publicKey(): ?string
    {
        return self::configured() ? (string) config('portal.push.public_key') : null;
    }

    /**
     * Whether the server may post to this endpoint: HTTPS, a known push service, no
     * credentials or port tricks. The host must be a plain DNS name, so nothing the
     * URL parser and the HTTP client could read differently ("evil.test;.notify.windows.com")
     * passes a ".suffix" rule; such a rule also needs a name in front of the suffix.
     */
    public static function allowsEndpoint(string $endpoint): bool
    {
        // Spaces or control characters are in no address a browser hands out.
        if (preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
            return false;
        }

        $parts = parse_url($endpoint);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower($parts['host']);

        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host) !== 1) {
            return false;
        }

        foreach (config('portal.push.endpoint_hosts') as $allowed) {
            $allowed = strtolower($allowed);

            if (str_starts_with($allowed, '.') ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /** Whether a push to this account would reach at least one device now. */
    public static function reaches(PortalUser $user): bool
    {
        return self::configured() && $user->pushSubscriptions()->live()->exists();
    }

    /**
     * Sends the message to every live device of the account, the most recently
     * reached first; returns how many push services took it. Stops before a
     * request could run past `portal.push.deliver_seconds`, so a notice whose
     * pushes stall still ends in time for the email fallback.
     */
    public function deliver(PortalUser $user, PushMessage $message): int
    {
        if (! self::configured()) {
            return 0;
        }

        $sent = 0;
        $deadline = hrtime(true) + (int) config('portal.push.deliver_seconds') * 1_000_000_000;
        $request = (int) config('portal.push.timeout') * 1_000_000_000;

        $devices = $user->pushSubscriptions()->live()
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();

        foreach ($devices as $subscription) {
            if (hrtime(true) + $request > $deadline) {
                break;
            }

            $sent += $this->sendTo($subscription, $message) ? 1 : 0;
        }

        return $sent;
    }

    /** One device (the "Send a test notification" button); false when it did not go. */
    public function sendTo(PortalPushSubscription $subscription, PushMessage $message): bool
    {
        if (! self::configured() || ! self::allowsEndpoint($subscription->endpoint)) {
            return false;
        }

        $result = $this->sender->send($subscription, $message);

        if ($result === PushResult::Expired) {
            $subscription->delete();

            return false;
        }

        if ($result === PushResult::Sent) {
            $subscription->forceFill(['last_used_at' => now()])->saveQuietly();

            return true;
        }

        return false;
    }

    /** Signing out of one session: the devices subscribed in it stop getting pushes. */
    public static function forgetSession(string $sessionId): int
    {
        return PortalPushSubscription::query()->where('portal_session_id', $sessionId)->delete();
    }

    /** "Sign out everywhere", or no practice left to hear from: every device of the account. */
    public static function forgetAccount(PortalUser|int $user): int
    {
        return PortalPushSubscription::query()
            ->where('portal_user_id', $user instanceof PortalUser ? $user->getKey() : $user)
            ->delete();
    }
}
