<?php

namespace App\Http\Resources;

use App\Models\AstrologyMethod;
use App\Models\Client;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /** Private notes are sent only with a single client, never in lists. */
    public bool $withNotes = false;

    /** @var array<string, int>|null counts for the profile's summary card */
    public ?array $stats = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'preferred_locale' => $this->preferred_locale,
            'status' => $this->status->value,
            'assigned_user_id' => $this->assigned_user_id,
            'internal_notes' => $this->when($this->withNotes, $this->internal_notes),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn (Tag $tag) => $tag->name)->values()),
            'methods' => $this->whenLoaded('astrologyMethods', fn () => $this->astrologyMethods->map(
                fn (AstrologyMethod $method) => [
                    'id' => $method->id,
                    'name' => $method->name,
                    'slug' => $method->slug,
                    'is_system' => $method->isSystem(),
                    'is_default' => (bool) $method->pivot->is_default,
                ]
            )->values()),
            'birth' => $this->whenLoaded('birthDetails', fn () => $this->birthDetails
                ? BirthDetailsResource::make($this->birthDetails)->resolve()
                : null),
            'stats' => $this->when($this->stats !== null, fn () => $this->stats),
            'last_activity_at' => $this->last_activity_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    public function withNotes(): static
    {
        $this->withNotes = true;

        return $this;
    }

    /**
     * @param  array<string, int>  $stats
     */
    public function withStats(array $stats): static
    {
        $this->stats = $stats;

        return $this;
    }
}
