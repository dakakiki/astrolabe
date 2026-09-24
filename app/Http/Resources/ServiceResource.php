<?php

namespace App\Http\Resources;

use App\Models\AstrologyMethod;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'price' => $this->price_amount === null ? null : [
                'amount' => $this->price_amount,
                'currency' => $this->currency,
            ],
            'currency' => $this->currency,
            'location_type' => $this->location_type->value,
            'color' => $this->color?->value,
            'requires_deposit' => $this->requires_deposit,
            'is_active' => $this->is_active,
            'methods' => $this->whenLoaded('astrologyMethods', fn () => $this->astrologyMethods->map(
                fn (AstrologyMethod $method) => [
                    'id' => $method->id,
                    'name' => $method->name,
                    'slug' => $method->slug,
                    'is_system' => $method->isSystem(),
                ]
            )->values()),
            // Whether a consultation or an appointment refers to it: then it can be deactivated, not deleted.
            'in_use' => $this->when(
                isset($this->used_by_consultations),
                fn () => (bool) $this->used_by_consultations || (bool) $this->used_by_appointments,
            ),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
