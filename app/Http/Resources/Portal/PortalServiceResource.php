<?php

namespace App\Http\Resources\Portal;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service the client can book in the portal (docs/spec/12, "Zakazivanje
 * (9b)"): what it is, how long it takes and where it is held — `either` lets
 * the client choose. The price is for information only (no payment in the
 * portal), with the currency's decimals so the portal can show it; whether a
 * deposit is expected is explained in the practice's note for clients.
 *
 * @mixin Service
 */
class PortalServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'location_type' => $this->location_type->value,
            'requires_deposit' => $this->requires_deposit,
            'price' => $this->price_amount === null ? null : [
                'amount' => $this->price_amount,
                'currency' => $this->currency,
                'decimals' => (int) config("astrolabe.currency_decimals.{$this->currency}", 2),
            ],
        ];
    }
}
