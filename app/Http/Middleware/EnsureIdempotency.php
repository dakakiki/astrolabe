<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 */
class EnsureIdempotency
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $request->user() === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return response()->json(['message' => __('appointments.idempotency_key_invalid')], 422);
        }

        $cacheKey = 'idempotency:'.$request->user()->getKey().':'.sha1($request->method().' '.$request->path().' '.$key);
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
}
