<?php

namespace App\Support\Operations;

use App\Notifications\OperatorAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Email to whoever runs the installation (`astrolabe.operator.email`) when
 * something breaks: a server error or a failing health check. No external error
 * service — the data stays on the server, and the email carries no message
 * text, query string or request data (an error message can quote a client's
 * name from SQL); the details are in the server's log.
 *
 * Sent at once, not through the queue, which may be what is broken. The same
 * problem is emailed at most once an hour.
 */
final class OperatorAlerts
{
    public const QUIET_SECONDS = 3600;

    public static function enabled(): bool
    {
        return filled(config('astrolabe.operator.email'));
    }

    public static function exception(Throwable $e): void
    {
        if (! self::enabled()) {
            return;
        }

        $where = self::relativePath($e->getFile()).':'.$e->getLine();
        $request = app()->runningInConsole() ? null : request();

        self::send('exception:'.sha1($e::class.'|'.$where), [
            'subject' => __('operations.exception.subject', ['app' => config('app.name'), 'type' => class_basename($e)]),
            'lines' => array_filter([
                __('operations.exception.type', ['type' => $e::class]),
                __('operations.exception.where', ['where' => $where]),
                $request ? __('operations.exception.request', ['method' => $request->method(), 'path' => '/'.ltrim($request->path(), '/')]) : __('operations.exception.console'),
                $request?->user() ? __('operations.exception.user', ['id' => $request->user()->getAuthIdentifier()]) : null,
                __('operations.when', ['time' => CarbonImmutable::now()->utc()->toDateTimeString()]),
                __('operations.exception.log'),
            ]),
        ]);
    }

    /**
     * @param  list<string>  $failing  names of the failing checks
     */
    public static function healthFailing(array $failing, int $newFailedJobs): void
    {
        if (! self::enabled()) {
            return;
        }

        sort($failing);

        self::send('health:'.implode(',', $failing).'|'.($newFailedJobs > 0 ? 'jobs' : ''), [
            'subject' => __('operations.health.subject', ['app' => config('app.name')]),
            'lines' => array_filter([
                $failing !== [] ? __('operations.health.failing', ['checks' => implode(', ', $failing)]) : null,
                $newFailedJobs > 0 ? trans_choice('operations.health.failed_jobs', $newFailedJobs, ['count' => $newFailedJobs]) : null,
                __('operations.when', ['time' => CarbonImmutable::now()->utc()->toDateTimeString()]),
                __('operations.health.log'),
            ]),
        ]);
    }

    public static function healthRecovered(): void
    {
        if (! self::enabled()) {
            return;
        }

        self::send(null, [
            'subject' => __('operations.recovered.subject', ['app' => config('app.name')]),
            'lines' => [
                __('operations.recovered.line'),
                __('operations.when', ['time' => CarbonImmutable::now()->utc()->toDateTimeString()]),
            ],
        ]);
    }

    /**
     * @param  array{subject: string, lines: array<int, string>}  $message
     */
    private static function send(?string $key, array $message): void
    {
        if ($key !== null && ! RateLimiter::attempt('operator-alert:'.$key, 1, fn () => true, self::QUIET_SECONDS)) {
            return;
        }

        try {
            Notification::route('mail', config('astrolabe.operator.email'))
                ->notifyNow(new OperatorAlert($message['subject'], array_values($message['lines'])));
        } catch (Throwable $failure) {
            // Never let the alert hide the original problem.
            Log::error('Operator alert could not be sent', ['exception' => $failure::class, 'message' => $failure->getMessage()]);
        }
    }

    private static function relativePath(string $file): string
    {
        $base = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file));
    }
}
