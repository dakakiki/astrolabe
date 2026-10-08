<?php

namespace App\Console\Commands;

use App\Support\Operations\HealthCheck;
use App\Support\Operations\OperatorAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs the health checks (every five minutes from the scheduler) and emails the
 * operator when one fails or queued jobs have failed since the last run — and
 * once more when everything passes again. A stopped scheduler cannot report
 * itself: that is what an outside monitor on /api/v1/health is for.
 */
class CheckHealth extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Check the database, cache, engine, storage, queue and scheduler; email the operator about problems';

    private const LAST_FAILED_JOB = 'health:last-failed-job-id';

    private const WAS_FAILING = 'health:was-failing';

    public function __construct(private readonly HealthCheck $health)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = $this->health->run();
        $failing = array_keys(array_filter($checks, fn (bool $ok) => ! $ok));
        $newFailedJobs = $this->newFailedJobs();

        foreach ($checks as $name => $ok) {
            $this->line(sprintf('%-10s %s', $name, $ok ? 'ok' : 'FAILING'));
        }

        if ($newFailedJobs > 0) {
            $this->warn("{$newFailedJobs} queued job(s) failed since the last check.");
        }

        if ($failing !== [] || $newFailedJobs > 0) {
            OperatorAlerts::healthFailing($failing, $newFailedJobs);
        } elseif (Cache::pull(self::WAS_FAILING)) {
            OperatorAlerts::healthRecovered();
        }

        if ($failing !== []) {
            Cache::forever(self::WAS_FAILING, true);
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Failed jobs added since the previous run; the first run only takes note. */
    private function newFailedJobs(): int
    {
        $table = config('queue.failed.table', 'failed_jobs');
        $latest = (int) DB::table($table)->max('id');
        $seen = Cache::get(self::LAST_FAILED_JOB);

        Cache::forever(self::LAST_FAILED_JOB, $latest);

        return $seen === null ? 0 : DB::table($table)->where('id', '>', (int) $seen)->count();
    }
}
