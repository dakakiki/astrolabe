<?php

namespace App\Http\Requests;

use App\Enums\AvailabilityKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Time off or extra time on top of the signed-in person's week (Phase 9b,
 * Settings → Availability), entered on the clock of `timezone`:
 *
 * - whole days (`all_day`): `starts_at` and `ends_at` are the first and the last
 *   day ("Y-m-d"), both included — stored from midnight of the first day to
 *   midnight after the last, so a day across a change of the clocks is 23 or 25 hours;
 * - otherwise local date-times ("Y-m-d\TH:i").
 *
 * It ends after it starts, has not ended yet and lasts at most 366 days. The note is the
 * astrologer's own; clients never see it (docs/spec/10).
 */
class SaveAvailabilityExceptionRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    /** Availability is personal: everyone in the practice keeps their own. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $format = $this->wholeDays() ? 'date_format:Y-m-d' : 'date_format:Y-m-d\TH:i';

        return [
            'kind' => ['required', Rule::enum(AvailabilityKind::class)],
            'all_day' => ['sometimes', 'boolean'],
            'starts_at' => ['required', 'string', $format],
            'ends_at' => ['required', 'string', $format],
            'timezone' => ['required', 'timezone:all_with_bc'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['all_day', 'starts_at', 'ends_at', 'timezone'])) {
                return;
            }

            [$start, $end] = $this->localPeriod();

            if ($end <= $start) {
                $validator->errors()->add('ends_at', __('booking.availability.end_after_start'));
            } elseif ($end > $start->addDays(self::MAX_DAYS)) {
                $validator->errors()->add('ends_at', __('booking.availability.too_long', ['days' => self::MAX_DAYS]));
            } elseif ($end <= CarbonImmutable::now()) {
                // Over already: it changes no free time, and the list (what has not ended) would never show it.
                $validator->errors()->add('ends_at', __('booking.availability.ended'));
            }
        }];
    }

    public function wholeDays(): bool
    {
        return $this->boolean('all_day');
    }

    /**
     * The stretch to store, in UTC: [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(): array
    {
        [$start, $end] = $this->localPeriod();

        return [$start->utc(), $end->utc()];
    }

    /**
     * The stretch on the entered clock; whole days run to midnight after the last day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function localPeriod(): array
    {
        $zone = (string) $this->input('timezone');

        if ($this->wholeDays()) {
            return [
                CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('starts_at'), $zone),
                CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('ends_at'), $zone)->addDay(),
            ];
        }

        return [
            CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $this->input('starts_at'), $zone),
            CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $this->input('ends_at'), $zone),
        ];
    }
}
