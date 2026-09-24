<?php

namespace App\Http\Resources;

use App\Models\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One timeline entry. The frontend words it from `type` and `metadata`, so
 * nothing here is translated text.
 *
 * @mixin ActivityEvent
 */
class ActivityEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->event_type->value,
            'category' => $this->event_type->category(),
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
            'subject' => ['type' => $this->subject_type, 'id' => $this->subject_id],
            'summary' => $this->summary,
            'visibility' => $this->visibility?->value,
            'metadata' => $this->metadata ?? (object) [],
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
        ];
    }
}
