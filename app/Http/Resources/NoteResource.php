<?php

namespace App\Http\Resources;

use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Note
 */
class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation ? [
                'id' => $this->consultation->id,
                'title' => $this->consultation->title,
                'starts_at' => $this->consultation->starts_at?->toIso8601ZuluString(),
            ] : null),
            'title' => $this->title,
            // Sanitized on the way in (App\Support\RichText); safe to render as HTML.
            'content' => $this->content,
            'visibility' => $this->visibility->value,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
