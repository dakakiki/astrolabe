<?php

namespace App\Support\Retention;

use App\Actions\Workspaces\DeletePractice;
use App\Enums\ExportStatus;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The retention rules (docs/spec/06, "pravila čuvanja i brisanja podataka"),
 * applied every night by `data:prune`; the periods are in config/astrolabe.php:
 *
 * - practices whose scheduled deletion is due are deleted for good (DeletePractice);
 * - deleted (trashed) files, notes, tasks, payments, consultations, appointments
 *   and related people go for good after `deleted_days` — files from the disk too;
 * - the audit log after `audit_log_months`;
 * - finished exports when they expire;
 * - expired or revoked invitations, failed queue jobs, used-up password
 *   reset links and expired cache entries (which may hold an idempotent
 *   response with a client in it) after their own short periods.
 *
 * Clients are never trashed: archiving is a status, and deleting a client is
 * immediate and for good (DeleteClient). Rows go with plain queries, children
 * before parents; one audit entry records the counts.
 */
final class Retention
{
    public function __construct(private readonly DeletePractice $deletePractice) {}

    /**
     * @return array<string, int> what was (or, on a dry run, would be) removed, by kind
     */
    public function apply(CarbonImmutable $now, bool $dryRun = false): array
    {
        $counts = ['practices' => $this->duePractices($now, $dryRun)];
        $counts += $this->trashed($now->subDays(config('astrolabe.retention.deleted_days')), $dryRun);
        $counts['audit_logs'] = $this->remove(
            DB::table('audit_logs')->where('created_at', '<', $now->subMonthsNoOverflow(config('astrolabe.retention.audit_log_months'))),
            $dryRun,
        );
        $counts['exports'] = $this->exports($now, $dryRun);
        $counts += $this->housekeeping($now, $dryRun);

        return $counts;
    }

    private function duePractices(CarbonImmutable $now, bool $dryRun): int
    {
        $due = Workspace::query()->whereNotNull('deletes_at')->where('deletes_at', '<=', $now)->orderBy('id')->get();

        if ($dryRun) {
            return $due->count();
        }

        $deleted = 0;

        foreach ($due as $workspace) {
            try {
                $this->deletePractice->handle($workspace);
                $deleted++;
            } catch (Throwable $e) {
                // The next night tries again; the operator hears of it now.
                report($e);
            }
        }

        return $deleted;
    }

    /**
     * Trashed rows older than the cutoff, children first: files (with the file
     * on the disk), notes, tasks, payments, then consultations and appointments
     * (whose leftover links empty themselves), then related people and their charts.
     *
     * @return array<string, int>
     */
    private function trashed(CarbonImmutable $cutoff, bool $dryRun): array
    {
        $old = fn (string $table) => DB::table($table)->whereNotNull('deleted_at')->where('deleted_at', '<=', $cutoff);

        $counts = ['files' => $this->files('attachments', $old('attachments'), $dryRun)];

        foreach (['notes', 'tasks', 'payments', 'consultations', 'appointments'] as $table) {
            $counts[$table] = $this->remove($old($table), $dryRun);
        }

        $people = $old('related_people')->pluck('id');

        if (! $dryRun && $people->isNotEmpty()) {
            DB::table('chart_calculations')->where('subject_type', 'related_person')->whereIn('subject_id', $people)->delete();
            DB::table('related_people')->whereIn('id', $people)->delete();
        }

        $counts['related_people'] = $people->count();

        return $counts;
    }

    /**
     * Ready exports past their date, and pending or failed ones left behind.
     */
    private function exports(CarbonImmutable $now, bool $dryRun): int
    {
        $exports = DB::table('workspace_exports')->where(fn ($query) => $query
            ->where('expires_at', '<=', $now)
            ->orWhere(fn ($query) => $query
                ->where('status', '!=', ExportStatus::Ready->value)
                ->where('created_at', '<=', $now->subDays(config('astrolabe.exports.keep_days')))));

        return $this->files('workspace_exports', $exports, $dryRun, 'disk', 'path');
    }

    /**
     * @return array<string, int>
     */
    private function housekeeping(CarbonImmutable $now, bool $dryRun): array
    {
        $cutoff = $now->subDays(config('astrolabe.retention.housekeeping_days'));
        $counts = [
            'invitations' => $this->remove(DB::table('registration_invitations')
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query->where('expires_at', '<=', $cutoff)->orWhere('revoked_at', '<=', $cutoff)), $dryRun),
            'failed_jobs' => $this->remove(DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '<=', $cutoff), $dryRun),
            'password_resets' => $this->remove(DB::table('password_reset_tokens')
                ->where('created_at', '<=', $now->subMinutes(config('auth.passwords.users.expire', 60))), $dryRun),
        ];

        if (config('cache.default') === 'database') {
            $counts['cache'] = $this->remove(
                DB::table(config('cache.stores.database.table', 'cache'))->where('expiration', '<=', $now->getTimestamp()),
                $dryRun,
            );
        }

        return $counts;
    }

    /** Rows that point at a stored file: the file first, then the row. */
    private function files(string $table, Builder $rows, bool $dryRun, string $diskColumn = 'storage_disk', string $pathColumn = 'storage_path'): int
    {
        if ($dryRun) {
            return $rows->count();
        }

        $removed = 0;

        $rows->chunkById(200, function ($chunk) use ($table, &$removed, $diskColumn, $pathColumn) {
            foreach ($chunk as $row) {
                if ($row->{$pathColumn} !== null) {
                    try {
                        Storage::disk($row->{$diskColumn} ?? config('astrolabe.attachments.disk'))->delete($row->{$pathColumn});
                    } catch (Throwable $e) {
                        Log::warning('A file past its retention could not be removed', ['path' => $row->{$pathColumn}, 'exception' => $e::class]);
                    }
                }
            }

            $removed += DB::table($table)->whereIn('id', $chunk->pluck('id'))->delete();
        });

        return $removed;
    }

    private function remove(Builder $rows, bool $dryRun): int
    {
        return $dryRun ? $rows->count() : $rows->delete();
    }
}
