<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AuditEvent;
use App\Enums\PortalNotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\PortalPushSubscription;
use App\Models\PortalUser;
use App\Support\Audit\Audit;
use App\Support\Portal\Push\PortalPush;
use App\Support\Portal\Push\PushMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Portal → Profile → Notifications, web push (Phase 9c, docs/spec/12, "Web
 * push"): whether push is offered, the account's devices that have it on,
 * switching it on or off for this browser, and a test notice to it.
 *
 * Push is per device and only after an explicit "Turn on" in the portal: the
 * browser's subscription is stored with the portal session it was made in and
 * ends with it (sign-out, "Sign out everywhere", expiry). The endpoint is the
 * browser's own key to its push service — it is never sent back; devices are
 * shown by its SHA-256, which the SPA computes for its own endpoint.
 */
class PushController extends Controller
{
    /** At most this many devices per account; the oldest goes when another switches push on. */
    public const MAX_DEVICES = 10;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($request)]);
    }

    /** This browser switches push on (or renews its subscription). */
    public function store(Request $request): JsonResponse
    {
        if (! PortalPush::configured()) {
            return response()->json(['message' => __('portal.errors.push_unavailable'), 'code' => 'push_unavailable'], 409);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        if (! PortalPush::allowsEndpoint($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => __('portal.errors.push_unsupported')]);
        }

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $hash = PortalPushSubscription::hashEndpoint($data['endpoint']);

        $created = DB::transaction(function () use ($request, $user, $data, $hash) {
            $existing = PortalPushSubscription::query()->where('endpoint_hash', $hash)->lockForUpdate()->first();

            // The same browser signed in to another account: it now belongs to this one.
            if ($existing !== null && (int) $existing->portal_user_id !== (int) $user->getKey()) {
                $existing->delete();
                $existing = null;
            }

            // No other device of the account has push on (the list in Profile is empty).
            $first = $existing === null && $user->pushSubscriptions()->live()->doesntExist();

            $subscription = $existing ?? new PortalPushSubscription(['portal_user_id' => $user->getKey()]);
            $subscription->fill([
                'portal_session_id' => $request->session()->getId(),
                'endpoint' => $data['endpoint'],
                'endpoint_hash' => $hash,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ])->save();

            // Keep the newest devices only.
            $user->pushSubscriptions()
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->skip(self::MAX_DEVICES)
                ->take(PHP_INT_MAX)
                ->get()
                ->each->delete();

            // The first device switched on: push joins email unless the person chose otherwise.
            // A further device leaves the choice as it is — "email" may then be the person's own.
            if ($first && $user->notification_channel === PortalNotificationChannel::Email) {
                $user->forceFill(['notification_channel' => PortalNotificationChannel::Both])->save();
            }

            return $existing === null;
        });

        if ($created) {
            Audit::portal(AuditEvent::PortalPushEnabled, $user);
        }

        return response()->json(['data' => $this->state($request)], $created ? 201 : 200);
    }

    /** This browser switches push off. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();

        $deleted = $user->pushSubscriptions()
            ->where('endpoint_hash', PortalPushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        if ($deleted > 0) {
            Audit::portal(AuditEvent::PortalPushDisabled, $user);
        }

        return response()->json(['data' => $this->state($request)]);
    }

    /** "Send a test notification" to this browser. */
    public function test(Request $request, PortalPush $push): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();

        $subscription = $user->pushSubscriptions()
            ->live()
            ->where('endpoint_hash', PortalPushSubscription::hashEndpoint($data['endpoint']))
            ->first();

        if ($subscription === null) {
            return response()->json(['message' => __('portal.errors.push_not_on'), 'code' => 'push_not_on'], 404);
        }

        $sent = $push->sendTo($subscription, new PushMessage(
            __('portal.push.test.title'),
            __('portal.push.test.body'),
            '/profile',
            'test',
        ));

        return response()->json(['data' => ['sent' => $sent]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Request $request): array
    {
        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $session = $request->session()->getId();

        return [
            'available' => PortalPush::configured(),
            'public_key' => PortalPush::publicKey(),
            'channel' => ($user->notification_channel ?? PortalNotificationChannel::Email)->value,
            'devices' => $user->pushSubscriptions()
                ->live()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (PortalPushSubscription $device) => [
                    'hash' => $device->endpoint_hash,
                    'current' => $device->portal_session_id !== null && hash_equals($device->portal_session_id, $session),
                    'user_agent' => $device->user_agent,
                    'created_at' => $device->created_at->toIso8601ZuluString(),
                    'last_used_at' => $device->last_used_at?->toIso8601ZuluString(),
                ])
                ->values(),
        ];
    }
}
