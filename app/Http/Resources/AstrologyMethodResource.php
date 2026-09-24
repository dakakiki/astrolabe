<?php

namespace App\Http\Resources;

use App\Models\AstrologyMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `selected` and `is_default` describe the current workspace's choice and are
 * present when the controller has loaded that selection.
 *
 * @mixin AstrologyMethod
 */
class AstrologyMethodResource extends JsonResource
{
    /** @var array<int, bool>|null method id => is_default, for the current workspace */
    public ?array $selection = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_system' => $this->isSystem(),
            'suggested_house_system' => $this->suggested_house_system?->value,
            'suggested_zodiac_mode' => $this->suggested_zodiac_mode?->value,
            'suggested_ayanamsa' => $this->suggested_ayanamsa?->value,
            'selected' => $this->when($this->selection !== null, fn () => array_key_exists($this->id, $this->selection)),
            'is_default' => $this->when($this->selection !== null, fn () => $this->selection[$this->id] ?? false),
        ];
    }

    /**
     * @param  array<int, bool>  $selection
     */
    public function withSelection(array $selection): static
    {
        $this->selection = $selection;

        return $this;
    }
}
