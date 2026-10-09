<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The performance pass (Phase 8c): every screen's API request against the
 * practice from `perf:seed`, run in this process as its owner — the first
 * time (cold caches, a chart may be calculated) and the median of the next
 * runs, with the number of queries and the size of the answer. Locally PHP
 * runs with Xdebug, so times are a ceiling; the query counts are what matter.
 * Never on production.
 */
class MeasurePerformance extends Command
{
    protected $signature = 'perf:measure
        {--runs=5 : Runs per request after the first}
        {--email='.SeedPerformanceData::EMAIL.' : Whose practice to measure}
        {--only= : Only requests whose label contains this}
        {--queries : Also list the queries of the last run, grouped by their SQL}';

    protected $description = 'Time the main API requests and count their queries on the perf practice';

    private int $queries = 0;

    private float $queryMs = 0;

    /** @var array<string, array{0: int, 1: float}> SQL => [how often, ms], in the current run */
    private array $statements = [];

    public function handle(HttpKernel $kernel): int
    {
        if (app()->isProduction()) {
            $this->error('perf:measure never runs on production.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->option('email'))->first();
        if ($user === null) {
            $this->error('No such account. Run php artisan perf:seed first.');

            return self::FAILURE;
        }

        $workspace = $user->current_workspace_id;
        $busiest = (int) DB::table('activity_events')->where('workspace_id', $workspace)
            ->select('client_id')->groupBy('client_id')->orderByRaw('count(*) desc')->limit(1)->value('client_id');
        $consultation = (int) DB::table('consultations')->where('client_id', $busiest)->whereNull('deleted_at')->orderByDesc('starts_at')->value('id');
        $today = CarbonImmutable::now($user->timezone ?: 'UTC');

        $requests = [
            'me' => '/api/v1/me',
            'reference data' => '/api/v1/reference-data',
            'dashboard' => '/api/v1/dashboard',
            'clients' => '/api/v1/clients',
            'clients, 100 a page' => '/api/v1/clients?status=all&per_page=100',
            'clients, search' => '/api/v1/clients?search=an',
            'clients, recent activity' => '/api/v1/clients?sort=-last_activity_at',
            'clients, tag' => '/api/v1/clients?tag=vip',
            'client' => "/api/v1/clients/{$busiest}",
            'client timeline' => "/api/v1/clients/{$busiest}/timeline",
            'client timeline, notes' => "/api/v1/clients/{$busiest}/timeline?type=notes",
            'client relationships' => "/api/v1/clients/{$busiest}/relationships",
            'client notes' => "/api/v1/notes?client_id={$busiest}",
            'client files' => "/api/v1/attachments?client_id={$busiest}",
            'client tasks' => "/api/v1/tasks?client_id={$busiest}&status=all",
            'client payments' => "/api/v1/payments?client_id={$busiest}",
            'client consultations' => "/api/v1/consultations?client_id={$busiest}",
            'client chart' => "/api/v1/clients/{$busiest}/chart",
            'consultations' => '/api/v1/consultations',
            'consultations, owed' => '/api/v1/consultations?billing=owed',
            'consultations, search' => '/api/v1/consultations?search=natal',
            'consultation' => "/api/v1/consultations/{$consultation}",
            'calendar, month' => '/api/v1/appointments?from='.$today->startOfMonth()->toDateString().'&to='.$today->endOfMonth()->toDateString(),
            'calendar, week' => '/api/v1/appointments?from='.$today->startOfWeek()->toDateString().'&to='.$today->endOfWeek()->toDateString(),
            'tasks' => '/api/v1/tasks',
            'tasks, overdue' => '/api/v1/tasks?due=overdue',
            'tasks, done' => '/api/v1/tasks?status=done',
            'payments' => '/api/v1/payments',
            'payments, summary' => '/api/v1/payments/summary',
            'payments, CSV' => '/api/v1/payments/export',
            'services' => '/api/v1/services',
            'tags' => '/api/v1/tags',
            'places, search' => '/api/v1/places?q=beog',
        ];

        DB::listen(function (QueryExecuted $query) {
            $this->queries++;
            $this->queryMs += $query->time;
            $this->statements[$query->sql] = [($this->statements[$query->sql][0] ?? 0) + 1, ($this->statements[$query->sql][1] ?? 0) + $query->time];
        });

        Auth::guard('web')->setUser($user);
        $runs = max(1, (int) $this->option('runs'));
        $rows = [];

        foreach ($requests as $label => $uri) {
            if ($this->option('only') && ! str_contains($label, (string) $this->option('only'))) {
                continue;
            }

            $first = $this->measure($kernel, $uri);
            $later = [];
            for ($i = 0; $i < $runs; $i++) {
                $later[] = $this->measure($kernel, $uri);
            }
            $median = collect($later)->sortBy('ms')->values()[intdiv(count($later), 2)];

            if ($this->option('queries')) {
                $this->line("<info>{$label}</info>");
                uasort($this->statements, fn (array $a, array $b) => $b[1] <=> $a[1]);
                foreach ($this->statements as $sql => [$times, $ms]) {
                    $this->line(sprintf('  %6.1f ms %3d × %s', $ms, $times, mb_substr($sql, 0, 200)));
                }
            }

            $rows[] = [
                $label,
                $median['status'],
                sprintf('%.0f', $first['ms']),
                sprintf('%.0f', $median['ms']),
                sprintf('%.0f', $median['db_ms']),
                $median['queries'],
                sprintf('%.1f', $median['bytes'] / 1024),
            ];
        }

        $this->table(['Request', 'Status', 'First ms', 'Median ms', 'DB ms', 'Queries', 'KB'], $rows);
        $this->line(sprintf('Practice %d, client %d (most timeline entries), %d runs after the first. PHP %s%s.',
            $workspace, $busiest, $runs, PHP_VERSION, extension_loaded('xdebug') ? ' with Xdebug' : ''));

        return self::SUCCESS;
    }

    /**
     * @return array{status: int, ms: float, db_ms: float, queries: int, bytes: int}
     */
    private function measure(HttpKernel $kernel, string $uri): array
    {
        $this->queries = 0;
        $this->queryMs = 0;
        $this->statements = [];
        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
        $started = hrtime(true);

        try {
            $response = $kernel->handle($request);
            $bytes = 0;
            if ($response instanceof StreamedResponse) {
                ob_start();
                $response->sendContent();
                $bytes = strlen((string) ob_get_clean());
            } else {
                $bytes = strlen((string) $response->getContent());
            }
            $kernel->terminate($request, $response);
            $status = $response->getStatusCode();

            if ($status >= 400 && ! $response instanceof StreamedResponse) {
                $this->warn("{$uri}: {$status} ".mb_substr((string) $response->getContent(), 0, 300));
            }
        } catch (Throwable $e) {
            $this->warn("{$uri}: ".$e::class.' '.$e->getMessage());
            $status = 0;
            $bytes = 0;
        }

        return [
            'status' => $status,
            'ms' => (hrtime(true) - $started) / 1e6,
            'db_ms' => $this->queryMs,
            'queries' => $this->queries,
            'bytes' => $bytes,
        ];
    }
}
