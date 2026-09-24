<?php

namespace App\Http\Resources;

use App\Models\ChartCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A calculated chart. Engine, ephemeris and tzdata versions always travel with
 * it: a chart is never shown without saying what produced it (docs/spec/02).
 *
 * @mixin ChartCalculation
 */
class ChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => 'ready',
            'chart_type' => $this->chart_type,
            'time_accuracy' => $this->time_accuracy->value,
            'zodiac_mode' => $this->zodiac_mode->value,
            'ayanamsa' => $this->ayanamsa?->value,
            'house_system' => $this->house_system?->value,
            'julian_day_ut' => round($this->julian_day_ut, 8),
            'positions' => $this->payload['positions'],
            'moon_range' => $this->payload['moon_range'] ?? null,
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
