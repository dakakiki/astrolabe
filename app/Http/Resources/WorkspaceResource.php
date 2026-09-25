<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'default_locale' => $this->default_locale,
            'timezone' => $this->timezone,
            // Derived from the time zone; used as the default country in forms.
            'country_code' => $this->countryCode(),
            'default_currency' => $this->default_currency,
            'default_house_system' => $this->default_house_system->value,
            'default_zodiac_mode' => $this->default_zodiac_mode->value,
            'default_ayanamsa' => $this->default_ayanamsa?->value,
            // Always complete: stored values laid over the defaults.
            'aspect_orbs' => $this->aspectSettings()->toArray(),
            'transit_orbs' => $this->transitSettings()->toArray(),
            'role' => $request->user()?->roleIn($this->resource)?->value,
        ];
    }
}
