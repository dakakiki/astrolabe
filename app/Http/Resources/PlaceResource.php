<?php

namespace App\Http\Resources;

use App\Astrology\ValueObjects\PlaceResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlaceResult
 */
class PlaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'country_code' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'population' => $this->population,
        ];
    }
}
