<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A ZIP of everything in the practice, asked for by its owner (Settings → Your
 * data; docs/spec/06, "izvoz podataka workspace-a"). Built by
 * BuildPracticeExport on the private exports disk, downloadable by the owner
 * until `expires_at`, then deleted with its file by `data:prune`. Deleting a
 * client for good deletes the practice's exports too: they contain the client.
 *
 * @property ExportStatus $status
 * @property array<string, int>|null $counts
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 */
class WorkspaceExport extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'counts' => 'array',
            'file_size' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Ready
            && $this->path !== null
            && $this->expires_at?->isFuture() === true;
    }

    /** The name the owner's browser saves it under. */
    public function downloadName(): string
    {
        return 'astrolabe-export-'.($this->completed_at ?? $this->created_at)->format('Y-m-d').'.zip';
    }

    /** Removes the file; the row goes with the caller's own delete. */
    public function deleteFile(): void
    {
        if ($this->disk !== null && $this->path !== null) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
