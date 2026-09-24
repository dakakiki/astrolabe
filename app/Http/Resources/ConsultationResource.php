<?php

namespace App\Http\Resources;

use App\Models\AstrologyMethod;
use App\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Consultation
 */
class ConsultationResource extends JsonResource
{
    /** Notes, summary and the chart snapshot are sent with a single consultation, never in lists. */
    public bool $withContent = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->fullName(),
                'status' => $this->client->status->value,
            ] : null),
            'client_id' => $this->client_id,
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'color' => $this->service->color?->value,
                'is_active' => $this->service->is_active,
            ] : null),
            'service_id' => $this->service_id,
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'starts_at' => $this->appointment->starts_at->toIso8601ZuluString(),
                'status' => $this->appointment->status->value,
            ] : null),
            'appointment_id' => $this->appointment_id,
            'title' => $this->title,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->toIso8601ZuluString(),
            'timezone' => $this->timezone,
            'starts_at_local' => $this->localStart()?->format('Y-m-d\TH:i'),
            'duration_minutes' => $this->duration_minutes,
            'topics' => $this->topics,
            'methods' => $this->whenLoaded('astrologyMethods', fn () => $this->astrologyMethods->map(
                fn (AstrologyMethod $method) => [
                    'id' => $method->id,
                    'name' => $method->name,
                    'slug' => $method->slug,
                    'is_system' => $method->isSystem(),
                ]
            )->values()),
            'has_chart' => $this->chart_calculation_id !== null,
            'internal_notes' => $this->when($this->withContent, $this->internal_notes),
            'client_summary' => $this->when($this->withContent, $this->client_summary),
            'next_steps' => $this->when($this->withContent, $this->next_steps),
            'chart' => $this->when(
                $this->withContent && $this->relationLoaded('chart'),
                fn () => $this->chart ? ChartResource::make($this->chart)->resolve() : null,
            ),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    public function withContent(): static
    {
        $this->withContent = true;

        return $this;
    }
}
