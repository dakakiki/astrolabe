<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\PortalAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One appointment. Times are UTC; `starts_at_local` and `timezone` give the
 * wall-clock time it was entered in. Internal notes travel only with a single
 * appointment, never in calendar lists.
 *
 * Booked in the portal (Phase 9b): `booking_source` says so, `client_note` is the
 * client's message (with a single appointment only, like notes), `cancelled_by`
 * and `declined_at` tell who called it off and whether a request was turned
 * down. `client_portal` (single appointment only) says whether the client can
 * use the portal now, so the screen can offer to tell them about a change.
 *
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    public bool $withNotes = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->fullName(),
                'status' => $this->client->status->value,
                'chart_ready' => $this->client->relationLoaded('birthDetails')
                    ? $this->client->birthDetails?->missingForChart() === []
                    : null,
            ] : null),
            'service_id' => $this->service_id,
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'color' => $this->service->color?->value,
                'is_active' => $this->service->is_active,
                'price' => $this->service->price_amount === null ? null : [
                    'amount' => $this->service->price_amount,
                    'currency' => $this->service->currency,
                ],
                'requires_deposit' => $this->service->requires_deposit,
            ] : null),
            // Deposits and other money paid for it: with a single appointment only.
            'payments' => $this->when(
                $this->withNotes && $this->relationLoaded('payments'),
                fn () => PaymentResource::collection($this->payments)->resolve(),
            ),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'timezone' => $this->timezone,
            'starts_at_local' => $this->localStart()->format('Y-m-d\TH:i'),
            'duration_minutes' => $this->durationMinutes(),
            'status' => $this->status->value,
            'location_type' => $this->location_type->value,
            'location_details' => $this->location_details,
            'booking_source' => $this->booking_source->value,
            'notes' => $this->when($this->withNotes, $this->notes),
            'client_note' => $this->when($this->withNotes, $this->client_note),
            // The email to the astrologer: how long before, when it goes out, whether it went.
            'reminder_minutes' => $this->reminder_minutes,
            'remind_at' => $this->remind_at?->toIso8601ZuluString(),
            'reminder_sent_at' => $this->reminder_sent_at?->toIso8601ZuluString(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by' => $this->cancelled_by?->value,
            'declined_at' => $this->declined_at?->toIso8601ZuluString(),
            'client_portal' => $this->when($this->withNotes, fn () => PortalAccess::query()
                ->usable()
                ->where('portal_access.client_id', $this->client_id)
                ->exists()),
            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation ? [
                'id' => $this->consultation->id,
                'status' => $this->consultation->status->value,
            ] : null),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    public function withNotes(): static
    {
        $this->withNotes = true;

        return $this;
    }
}
