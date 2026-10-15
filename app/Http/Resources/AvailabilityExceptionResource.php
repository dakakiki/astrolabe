<?php

namespace App\Http\Resources;

use App\Models\AvailabilityException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Time off or extra time (Phase 9b, Settings → Availability). `starts_at` and
 * `ends_at` are UTC; `starts_local` and `ends_local` give it back as it was
 * entered on the clock of `timezone` — dates for whole days (the last day
 * included), "Y-m-d\TH:i" otherwise. Only its owner ever reads it.
 *
 * @mixin AvailabilityException
 */
class AvailabilityExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $start = $this->starts_at->setTimezone($this->timezone);
        $end = $this->ends_at->setTimezone($this->timezone);

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'timezone' => $this->timezone,
            'all_day' => $this->all_day,
            'note' => $this->note,
            'starts_local' => $this->all_day ? $start->format('Y-m-d') : $start->format('Y-m-d\TH:i'),
            // Whole days end at midnight after the last one; the form shows that last day.
            'ends_local' => $this->all_day ? $end->subDay()->format('Y-m-d') : $end->format('Y-m-d\TH:i'),
        ];
    }
}
