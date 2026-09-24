<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Support\Attachments\AllowedFileTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file or a link. Storage details (disk, path, checksum) never leave the
 * server; files are fetched through the authorized download route.
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'consultation_id' => $this->consultationId(),
            'kind' => $this->kind->value,
            'name' => $this->original_name,
            'url' => $this->url,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'previewable' => AllowedFileTypes::showsInline($this->mime_type),
            'download_url' => $this->isFile() ? route('api.v1.attachments.download', $this->resource, false) : null,
            'visibility' => $this->visibility->value,
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null),
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
