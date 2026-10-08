<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\Scopes\WorkspaceScope;
use App\Models\WorkspaceExport;
use App\Notifications\PracticeExportReady;
use App\Support\DataExport\PracticeExport;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a practice export in the background (Settings → Your data): the ZIP is
 * written to a temporary file, moved to the private exports disk, and the owner
 * who asked gets an email with a link. One try: a failed export is shown as
 * failed and can simply be asked for again.
 */
class BuildPracticeExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    public function handle(PracticeExport $builder, CurrentWorkspace $current): void
    {
        $export = WorkspaceExport::withoutGlobalScope(WorkspaceScope::class)->with('workspace')->find($this->exportId);

        if ($export === null || $export->status !== ExportStatus::Pending) {
            return;
        }

        $disk = config('astrolabe.exports.disk');
        $path = sprintf('%d/%s.zip', $export->workspace_id, Str::uuid()->toString());
        $temporary = tempnam(sys_get_temp_dir(), 'practice-export');

        try {
            $counts = $current->run($export->workspace, fn () => $builder->write($export->workspace, $temporary));

            $stream = fopen($temporary, 'r');
            Storage::disk($disk)->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);

            $now = CarbonImmutable::now();
            $export->forceFill([
                'status' => ExportStatus::Ready,
                'disk' => $disk,
                'path' => $path,
                'file_size' => filesize($temporary) ?: null,
                'counts' => $counts,
                'completed_at' => $now,
                'expires_at' => $now->addDays(config('astrolabe.exports.keep_days')),
            ])->save();
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        } finally {
            @unlink($temporary);
        }

        $export->requester?->notify(new PracticeExportReady($export));
    }

    public function failed(?Throwable $exception): void
    {
        WorkspaceExport::withoutGlobalScope(WorkspaceScope::class)
            ->whereKey($this->exportId)
            ->update(['status' => ExportStatus::Failed->value, 'updated_at' => now()]);
    }
}
