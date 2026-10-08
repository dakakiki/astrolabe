<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\Audit;
use App\Support\Retention\Retention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Applies the retention rules (Retention) every night: due practice deletions,
 * deleted rows past their period with their files, the old audit log, expired
 * exports and the small leftovers. `--dry-run` only counts.
 */
class PruneData extends Command
{
    protected $signature = 'data:prune {--dry-run : Count what would be removed without removing it}';

    protected $description = 'Delete for good what the retention rules no longer keep';

    public function handle(Retention $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = $retention->apply(CarbonImmutable::now(), $dryRun);

        $this->table(['What', $dryRun ? 'Would remove' : 'Removed'], collect($counts)
            ->map(fn (int $count, string $what) => [$what, $count])
            ->values()
            ->all());

        if (! $dryRun && array_filter($counts) !== []) {
            Audit::system(AuditEvent::RetentionApplied, properties: array_filter($counts));
        }

        return self::SUCCESS;
    }
}
