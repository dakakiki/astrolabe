<?php

namespace App\Http\Resources;

use App\Models\WorkspaceExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A practice export: its state, size, what went in, and until when it can be
 * downloaded. The file itself only through the download route.
 *
 * @mixin WorkspaceExport
 */
class WorkspaceExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'file_size' => $this->file_size,
            'counts' => $this->counts,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),
            'downloadable' => $this->isDownloadable(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
        ];
    }
}
