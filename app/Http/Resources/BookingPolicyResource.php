<?php

namespace App\Http\Resources;

use App\Enums\AvailabilityKind;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\BookingPolicy;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How clients book in the portal (Phase 9b, Settings → Booking), with what the
 * page needs to say whether booking can work yet: who bookings go to (the
 * practice's owner until teams), how many services clients may book, whether
 * that astrologer has set working hours, and where the portal is.
 *
 * Unsaved, it is the defaults (BookingPolicy::current()). Read for one
 * practice at a time, so its few counting queries are fine.
 *
 * @mixin BookingPolicy
 */
class BookingPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $astrologer = $this->astrologer();

        return [
            'enabled' => $this->enabled,
            'min_notice_minutes' => $this->min_notice_minutes,
            'max_days_ahead' => $this->max_days_ahead,
            'buffer_before_minutes' => $this->buffer_before_minutes,
            'buffer_after_minutes' => $this->buffer_after_minutes,
            'slot_step_minutes' => $this->slot_step_minutes,
            'requires_confirmation' => $this->requires_confirmation,
            'client_reschedule_until_hours' => $this->client_reschedule_until_hours,
            'client_cancel_until_hours' => $this->client_cancel_until_hours,
            'client_note' => $this->client_note,
            'astrologer' => $astrologer ? ['id' => $astrologer->id, 'name' => $astrologer->name] : null,
            'bookable_services' => Service::query()->where('is_active', true)->where('bookable_in_portal', true)->count(),
            // A working week, or at least some extra time still ahead.
            'has_availability' => $astrologer !== null
                && (AvailabilityRule::query()->where('user_id', $astrologer->getKey())->exists()
                    || AvailabilityException::query()
                        ->where('user_id', $astrologer->getKey())
                        ->where('kind', AvailabilityKind::Available->value)
                        ->where('ends_at', '>', now())
                        ->exists()),
            'portal_url' => config('portal.url'),
        ];
    }
}
