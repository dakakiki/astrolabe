<?php

namespace App\Support\Operations;

use App\Astrology\Contracts\EphemerisEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether the parts the application depends on work (docs/spec/06, "health
 * check, uključujući proveru dostupnosti ephemeris engine-a"). Each check is a
 * yes or no; why a check failed goes to the log, never into the answer.
 *
 * - database: a query answers;
 * - cache: a value can be stored and read back (sessions and the queue use the database too);
 * - engine: the ephemeris program and data files are in place;
 * - storage: the client-files directory can be written;
 * - queue: no job has waited longer than QUEUE_STUCK_SECONDS (the worker runs);
 * - scheduler: the heartbeat the scheduler writes every minute is recent (cron runs).
 */
final class HealthCheck
{
    public const SCHEDULER_HEARTBEAT = 'health:scheduler-heartbeat';

    public const QUEUE_STUCK_SECONDS = 600;

    public const SCHEDULER_STALE_SECONDS = 180;

    public function __construct(private readonly EphemerisEngine $engine) {}

    /**
     * @return array<string, bool>
     */
    public function run(): array
    {
        return [
            'database' => $this->check('database', fn () => DB::select('select 1') !== []),
            'cache' => $this->check('cache', function () {
                $value = (string) random_int(1, PHP_INT_MAX);
                Cache::put('health:probe', $value, 60);

                return Cache::get('health:probe') === $value;
            }),
            'engine' => $this->check('engine', fn () => $this->engine->available()),
            'storage' => $this->check('storage', fn () => $this->storageIsWritable()),
            'queue' => $this->check('queue', fn () => $this->queueIsMoving()),
            'scheduler' => $this->check('scheduler', fn () => $this->schedulerIsBeating()),
        ];
    }

    /** Written by the scheduler every minute (routes/console.php). */
    public static function beat(): void
    {
        Cache::forever(self::SCHEDULER_HEARTBEAT, CarbonImmutable::now()->getTimestamp());
    }

    /**
     * @param  callable(): bool  $check
     */
    private function check(string $name, callable $check): bool
    {
        try {
            if ($check()) {
                return true;
            }

            Log::warning("Health check failed: {$name}");
        } catch (Throwable $e) {
            Log::warning("Health check failed: {$name}", ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return false;
    }

    private function storageIsWritable(): bool
    {
        $disk = config('astrolabe.attachments.disk');

        // A bucket is checked by its provider; only a local directory can fill up or lose permissions here.
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return true;
        }

        $root = (string) config("filesystems.disks.{$disk}.root");

        return is_dir($root) && is_writable($root);
    }

    private function queueIsMoving(): bool
    {
        if (config('queue.default') !== 'database') {
            return true;
        }

        $table = config('queue.connections.database.table', 'jobs');

        return ! DB::table($table)
            ->whereNull('reserved_at')
            ->where('available_at', '<', CarbonImmutable::now()->getTimestamp() - self::QUEUE_STUCK_SECONDS)
            ->exists();
    }

    private function schedulerIsBeating(): bool
    {
        $last = Cache::get(self::SCHEDULER_HEARTBEAT);

        return is_int($last) && $last >= CarbonImmutable::now()->getTimestamp() - self::SCHEDULER_STALE_SECONDS;
    }
}
