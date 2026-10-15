<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a creating request safe to repeat (docs/spec/10): a request sent again
 * with the same `Idempotency-Key` — a double click, a retry after a dropped
 * connection — gets the first response back instead of creating a second
 * record. Keys belong to one user and one endpoint and live for a day.
 *
 * Only successful responses are remembered, so a request refused for an
 * overlap can be repeated with the same key once the overlap is confirmed.
 * The same key with a different body is a client error (422).
 *
 * In the client portal (docs/spec/12, Phase 9b: booking) the key belongs to the
 * signed-in portal account instead, kept apart from the astrologers' keys.
 */
class EnsureIdempotency
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $owner = $key === null ? null : self::owner($request);

        if ($owner === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return response()->json(['message' => __('appointments.idempotency_key_invalid')], 422);
        }

        $cacheKey = 'idempotency:'.$owner.':'.sha1($request->method().' '.$request->path().' '.$key);
        $fingerprint = sha1(json_encode($request->all()) ?: '');

        $lock = Cache::lock($cacheKey.':lock', 30);

        if (! $lock->get()) {
            return response()->json(['message' => __('appointments.idempotency_in_progress')], 409);
        }

        try {
            if ($stored = Cache::get($cacheKey)) {
                if ($stored['fingerprint'] !== $fingerprint) {
                    return response()->json(['message' => __('appointments.idempotency_key_reused')], 422);
                }

                return response($stored['body'], $stored['status'])
                    ->header('Content-Type', 'application/json')
                    ->header('Idempotent-Replayed', 'true');
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whose keys these are: the signed-in astrologer's in the application; on the
     * portal's host the portal account's (the portal never signs anyone in on the
     * default guard). Both are numbered from 1, so a portal account's keys carry
     * their own prefix and never meet an astrologer's with the same number.
     * Null when nobody is signed in: then there is nothing to remember.
     */
    private static function owner(Request $request): ?string
    {
        if ($request->getHost() === config('portal.domain')) {
            $id = Auth::guard('portal')->id();

            return $id === null ? null : 'portal:'.$id;
        }

        $id = $request->user()?->getKey();

        return $id === null ? null : (string) $id;
    }
}
