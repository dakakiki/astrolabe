<?php

namespace App\Http\Resources\Portal;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Models\Appointment;
use App\Support\Portal\PortalBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An appointment as the client sees it in the portal (docs/spec/12, "Šta
 * klijent vidi"): when, how long, which service, online or in person — and,
 * while it is ahead and booked, the link or place. Never the astrologer's
 * notes, the client's own message to the astrologer, the reason the practice
 * wrote for cancelling, or anything about money.
 *
 * Phase 9b adds where it stands: a request waiting for the practice is ahead
 * too; a cancelled one says who cancelled it, and a declined request carries
 * the practice's message for the client. Whether the client may still move or
 * cancel it here, and until when, follows the practice's booking policy, read
 * once per request (PortalBooking). Moving needs the free times of the
 * practice's booking astrologer, so an appointment with anyone else is not
 * moved in the portal.
 *
 * @mixin Appointment
 */
class PortalAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $upcoming = $this->status->isOpen() && $this->ends_at->isFuture();
        $cancelled = $this->status === AppointmentStatus::Cancelled;
        $declined = $cancelled && $this->isDeclined();
        [$rescheduleUntil, $cancelUntil] = $upcoming ? $this->deadlines($request) : [null, null];
        $now = CarbonImmutable::now();

        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'duration_minutes' => $this->durationMinutes(),
            'status' => $this->status->value,
            'upcoming' => $upcoming,
            'service' => $this->service?->name,
            'location_type' => $this->location_type->value,
            'location_details' => $upcoming && $this->status === AppointmentStatus::Scheduled ? $this->location_details : null,
            'booked_in_portal' => $this->booking_source === BookingSource::Portal,
            'cancelled_by' => $cancelled ? $this->cancelled_by?->value : null,
            'declined' => $declined,
            'decline_reason' => $declined ? $this->cancellation_reason : null,
            'can_reschedule' => $rescheduleUntil !== null && $now < $rescheduleUntil,
            'can_cancel' => $cancelUntil !== null && $now < $cancelUntil,
            'reschedule_until' => $rescheduleUntil?->toIso8601ZuluString(),
            'cancel_until' => $cancelUntil?->toIso8601ZuluString(),
        ];
    }

    /**
     * Until when the client may move and cancel it in the portal; null = not here at all.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function deadlines(Request $request): array
    {
        $policy = PortalBooking::policy($request);
        $reschedule = $policy->rescheduleDeadline($this->resource);

        if ($reschedule !== null && (int) $this->assigned_user_id !== PortalBooking::astrologerId($request)) {
            $reschedule = null;
        }

        return [$reschedule, $policy->cancelDeadline($this->resource)];
    }
}
