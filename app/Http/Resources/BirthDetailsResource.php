<?php

namespace App\Http\Resources;

use App\Models\ClientBirthDetails;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Birth data as entered, plus what the server derives from it: whether a chart
 * can be calculated, and the birth moment with its historical UTC offset so
 * the astrologer can check it (war time, local summer time).
 *
 * @mixin ClientBirthDetails
 */
class BirthDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $moment = $this->localMoment();

        return [
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'birth_time' => $this->birth_time ? substr($this->birth_time, 0, 5) : null,
            'time_accuracy' => $this->time_accuracy->value,
            'birth_place' => $this->birth_place,
            'birth_country_code' => $this->birth_country_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'birth_timezone' => $this->birth_timezone,
            'place_id' => $this->place_id,
            'geocode_source' => $this->geocode_source?->value,
            'data_source' => $this->data_source,
            'notes' => $this->notes,

            'chart' => [
                'ready' => $this->missingForChart() === [],
                'missing' => $this->missingForChart(),
            ],
            'moment' => $moment ? [
                'utc' => $moment->utc()->format('Y-m-d\TH:i:s\Z'),
                'utc_offset' => $moment->format('P'),
                'abbreviation' => $moment->format('T'),
                'is_dst' => $moment->isDST(),
            ] : null,
            'clock_change' => $this->clockChangeIssue(),
            'zone_history_uncertain' => $this->hasUncertainZoneHistory(),
        ];
    }
}
