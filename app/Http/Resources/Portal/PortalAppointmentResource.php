<?php

namespace App\Http\Resources\Portal;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An appointment as the client sees it in the portal (docs/spec/12, "Šta
 * klijent vidi"): when, how long, which service, online or in person — and,
 * while it is ahead, the link or place. Never the astrologer's notes, the
 * reason they wrote for cancelling, or anything about money.
 *
 * @mixin Appointment
 */
class PortalAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $upcoming = $this->status === AppointmentStatus::Scheduled && $this->ends_at->isFuture();

        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'duration_minutes' => $this->durationMinutes(),
            'status' => $this->status->value,
            'upcoming' => $upcoming,
            'service' => $this->service?->name,
            'location_type' => $this->location_type->value,
            'location_details' => $upcoming ? $this->location_details : null,
        ];
    }
}
