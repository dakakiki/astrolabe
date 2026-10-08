<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Astrology\Contracts\EphemerisEngine;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Support\Audit\Audit;
use App\Support\Backup\Backups;
use App\Support\Operations\AppVersion;
use App\Support\Operations\HealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The admin's System screen (Phase 8c): the health checks, versions, how big
 * the database and the files are, the backups, the queue — and the failed
 * jobs, which the operator can run again or drop. A failed job shows its kind
 * and the exception's class only: the message and payload can quote a client.
 */
class AdminSystemController extends Controller
{
    /** The GeoNames copy: most of the database, and re-imported rather than backed up. */
    private const GAZETTEER = ['places', 'place_names'];

    public function show(HealthCheck $health, EphemerisEngine $engine, Backups $backups): JsonResponse
    {
        Audit::record(AuditEvent::AdminViewed, properties: ['screen' => 'system']);

        $tables = collect(DB::select(
            'select table_name as name, coalesce(data_length, 0) + coalesce(index_length, 0) as bytes from information_schema.tables where table_schema = database()',
        ));
        $gazetteer = (int) $tables->whereIn('name', self::GAZETTEER)->sum('bytes');
        $latestBackup = $backups->latest();
        $heartbeat = Cache::get(HealthCheck::SCHEDULER_HEARTBEAT);
        $storagePath = storage_path('app');

        return response()->json(['data' => [
            'checks' => $health->run(),
            'versions' => [
                'app' => AppVersion::current(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database' => DB::selectOne('select version() as v')->v,
                'engine' => $this->engine($engine),
            ],
            'database' => [
                'total_bytes' => (int) $tables->sum('bytes'),
                'gazetteer_bytes' => $gazetteer,
                'tables' => $tables->count(),
            ],
            'storage' => [
                'files' => DB::table('attachments')->whereNotNull('storage_path')->count(),
                'file_bytes' => (int) DB::table('attachments')->sum('file_size'),
                'export_bytes' => (int) DB::table('workspace_exports')->sum('file_size'),
                'disk_free_bytes' => @disk_free_space($storagePath) ?: null,
                'disk_total_bytes' => @disk_total_space($storagePath) ?: null,
            ],
            'backups' => [
                'configured' => filled(config('astrolabe.backup.key')),
                'count' => $backups->all()->count(),
                'latest' => $latestBackup ? [
                    'name' => $latestBackup['name'],
                    'created_at' => $latestBackup['created_at'],
                    'bytes' => (int) ($latestBackup['database']['bytes'] ?? 0) + (int) ($latestBackup['files']['bytes'] ?? 0),
                ] : null,
            ],
            'queue' => [
                'pending' => config('queue.default') === 'database' ? DB::table(config('queue.connections.database.table', 'jobs'))->count() : null,
                'failed' => DB::table(config('queue.failed.table', 'failed_jobs'))->count(),
                'scheduler_beat_at' => is_int($heartbeat) ? CarbonImmutable::createFromTimestamp($heartbeat)->toIso8601ZuluString() : null,
            ],
            'failed_jobs' => DB::table(config('queue.failed.table', 'failed_jobs'))
                ->orderByDesc('failed_at')
                ->limit(50)
                ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(fn (object $job) => [
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'job' => class_basename((string) (json_decode($job->payload, true)['displayName'] ?? 'unknown')),
                    'exception' => self::exceptionClass($job->exception),
                    'failed_at' => CarbonImmutable::parse($job->failed_at, 'UTC')->toIso8601ZuluString(),
                ]),
        ]]);
    }

    public function retry(string $uuid): Response
    {
        $this->failedJob($uuid);

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Audit::record(AuditEvent::AdminJobRetried, properties: ['job' => $uuid]);

        return response()->noContent();
    }

    public function forget(string $uuid): Response
    {
        $this->failedJob($uuid);

        Artisan::call('queue:forget', ['id' => $uuid]);
        Audit::record(AuditEvent::AdminJobDeleted, properties: ['job' => $uuid]);

        return response()->noContent();
    }

    private function failedJob(string $uuid): void
    {
        abort_unless(
            DB::table(config('queue.failed.table', 'failed_jobs'))->where('uuid', $uuid)->exists(),
            Response::HTTP_NOT_FOUND,
            __('admin.job_missing'),
        );
    }

    /** "Illuminate\Database\QueryException" from the stored trace — never the message. */
    public static function exceptionClass(?string $exception): ?string
    {
        return preg_match('/^([A-Za-z_][\w\\\\]*)/', (string) $exception, $match) === 1 ? $match[1] : null;
    }

    /**
     * @return array{name: string, version: string|null, available: bool}
     */
    private function engine(EphemerisEngine $engine): array
    {
        try {
            return ['name' => $engine->name(), 'version' => $engine->version(), 'available' => $engine->available()];
        } catch (Throwable) {
            return ['name' => $engine->name(), 'version' => null, 'available' => false];
        }
    }
}
