<?php

namespace App\Support\Portal\Push;

use App\Models\PortalPushSubscription;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Web push through `minishlink/web-push` (MIT): the payload encrypted for the
 * browser (aes128gcm), the server identified with the VAPID keys from
 * config('portal.push'). One request per push, Guzzle with a short timeout.
 *
 * Failures are logged without the endpoint or the payload (the endpoint works
 * like a key; the payload is about the client's appointment).
 */
final class WebPushSender implements PushSender
{
    private ?WebPush $webPush = null;

    /**
     * @param  ClientInterface|null  $client  The HTTP client to the push services: Guzzle with
     *                                        the short timeout when none is given; tests hand in
     *                                        one that answers like a push service.
     */
    public function __construct(private readonly ?ClientInterface $client = null) {}

    public function send(PortalPushSubscription $subscription, PushMessage $message): PushResult
    {
        try {
            $report = $this->webPush()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    // The column's default; the library's own would be the old "aesgcm".
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                $message->payload(),
                array_filter([
                    'TTL' => (int) config('portal.push.ttl'),
                    'urgency' => 'normal',
                    // A newer push about the same thing replaces one not yet delivered.
                    'topic' => $message->tag === null ? null : substr(hash('sha256', $message->tag), 0, 32),
                ], fn ($value) => $value !== null),
            );
        } catch (Throwable $e) {
            Log::warning('Web push failed before reaching the push service.', [
                'subscription' => $subscription->getKey(),
                'error' => $e::class,
            ]);

            return PushResult::Failed;
        }

        if ($report->isSuccess()) {
            return PushResult::Sent;
        }

        if ($report->isSubscriptionExpired()) {
            return PushResult::Expired;
        }

        // Only the status: the library's reason may quote the endpoint.
        $status = $report->getResponse()?->getStatusCode();

        Log::warning($status === null ? 'Web push did not reach the push service.' : 'Web push was refused by the push service.', [
            'subscription' => $subscription->getKey(),
            'status' => $status,
        ]);

        return PushResult::Failed;
    }

    private function webPush(): WebPush
    {
        if ($this->webPush !== null) {
            return $this->webPush;
        }

        $factory = new HttpFactory;

        return $this->webPush = new WebPush(
            auth: ['VAPID' => [
                'subject' => config('portal.push.subject'),
                'publicKey' => config('portal.push.public_key'),
                'privateKey' => config('portal.push.private_key'),
            ]],
            client: $this->client ?? new Client([
                'timeout' => config('portal.push.timeout'),
                'connect_timeout' => config('portal.push.connect_timeout'),
                'allow_redirects' => false,
                'http_errors' => false,
            ]),
            requestFactory: $factory,
            streamFactory: $factory,
            // The library's own checks (no GMP or BCMath, an old OpenSSL) go to the log; left
            // to trigger_error() they become an exception in Laravel and stop every push.
            logger: Log::channel(),
        );
    }
}
