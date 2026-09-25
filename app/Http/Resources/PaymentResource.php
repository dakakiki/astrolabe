<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment or refund. `amount` is positive in the currency's smallest unit;
 * `kind` says which way the money went.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_on' => $this->paid_on->format('Y-m-d'),
            'method' => $this->method?->value,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->fullName(),
                'status' => $this->client->status->value,
            ] : null),
            'consultation_id' => $this->consultation_id,
            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation ? [
                'id' => $this->consultation->id,
                'title' => $this->consultation->title ?? $this->consultation->service?->name,
                'starts_at' => $this->consultation->starts_at?->toIso8601ZuluString(),
                'status' => $this->consultation->status->value,
            ] : null),
            'appointment_id' => $this->appointment_id,
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'starts_at' => $this->appointment->starts_at->toIso8601ZuluString(),
                'status' => $this->appointment->status->value,
            ] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
