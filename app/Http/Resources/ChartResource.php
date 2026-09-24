<?php

namespace App\Http\Resources;

use App\Models\ChartCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A calculated chart. Engine, ephemeris and tzdata versions always travel with
 * it: a chart is never shown without saying what produced it (docs/spec/02).
 *
 * Charts stored before Phase 5 (payload version 1, e.g. an old consultation
 * snapshot) have positions only; their houses, angles and aspects are null.
 *
 * @mixin ChartCalculation
 */
class ChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->payload;

        return [
            'id' => $this->id,
            'status' => 'ready',
            'version' => $payload['version'] ?? 1,
            'chart_type' => $this->chart_type,
            'time_accuracy' => $this->time_accuracy->value,
            'zodiac_mode' => $this->zodiac_mode->value,
            'ayanamsa' => $this->ayanamsa?->value,
            'house_system' => $this->house_system?->value,
            'julian_day_ut' => round($this->julian_day_ut, 8),
            'location' => $payload['location'] ?? null,
            'positions' => $payload['positions'],
            'moon_range' => $payload['moon_range'] ?? null,
            // {system, requested_system, cusps}: system differs from requested_system
            // where the requested one cannot be drawn at the birth latitude.
            'houses' => $payload['houses'] ?? null,
            'angles' => $payload['angles'] ?? null,
            'aspects' => $payload['aspects'] ?? null,
            'aspect_settings' => $payload['aspect_settings'] ?? null,
            'engine' => [
                'name' => $this->engine_name,
                'version' => $this->engine_version,
                'ephemeris' => $this->ephemeris_version,
                'tzdata' => $this->tzdata_version,
            ],
            'calculated_at' => $this->calculated_at->toIso8601ZuluString(),
        ];
    }
}
