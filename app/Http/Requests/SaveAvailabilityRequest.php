<?php

namespace App\Http\Requests;

use App\Models\AvailabilityRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The signed-in person's whole working week (Phase 9b, Settings → Availability;
 * docs/spec/12, "Zakazivanje (9b)"): every stretch of every weekday, sent at
 * once and replacing what was there, as wall-clock times in one zone.
 *
 * A weekday is 1 (Monday) … 7 (Sunday); times are "HH:MM", and a stretch may
 * end at "24:00" (midnight). A stretch ends after it starts, stretches of one
 * day do not overlap (touching is fine), and a day has at most ten of them.
 * An empty list means no working hours: nothing is offered in the portal.
 */
class SaveAvailabilityRequest extends FormRequest
{
    public const MAX_PER_DAY = 10;

    private const START = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const END = '/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/';

    /** Availability is personal: everyone in the practice keeps their own. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timezone' => ['required', 'timezone:all_with_bc'],
            'rules' => ['present', 'array', 'max:'.(7 * self::MAX_PER_DAY)],
            'rules.*' => ['array:weekday,start_time,end_time'],
            'rules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'rules.*.start_time' => ['required', 'string', 'regex:'.self::START],
            'rules.*.end_time' => ['required', 'string', 'regex:'.self::END],
        ];
    }

    public function messages(): array
    {
        return [
            'rules.*.start_time.regex' => __('booking.availability.time'),
            'rules.*.end_time.regex' => __('booking.availability.time'),
        ];
    }

    /**
     * Each stretch ends after it starts; one day's stretches neither overlap
     * nor number more than ten.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $errors = $validator->errors();
            $days = [];

            if ($errors->has('rules') || ! is_array($this->input('rules'))) {
                return;
            }

            foreach ($this->input('rules') as $index => $rule) {
                if (! is_array($rule) || $errors->has("rules.{$index}") || $errors->has("rules.{$index}.*")) {
                    continue;
                }

                $start = AvailabilityRule::minutes($rule['start_time']);
                $end = AvailabilityRule::minutes($rule['end_time']);

                if ($end <= $start) {
                    $errors->add("rules.{$index}.end_time", __('booking.availability.end_after_start'));

                    continue;
                }

                $days[(int) $rule['weekday']][] = ['index' => $index, 'start' => $start, 'end' => $end];
            }

            foreach ($days as $stretches) {
                if (count($stretches) > self::MAX_PER_DAY) {
                    $errors->add('rules', __('booking.availability.too_many', ['max' => self::MAX_PER_DAY]));
                }

                usort($stretches, fn (array $a, array $b) => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);
                $reach = null;

                foreach ($stretches as $stretch) {
                    if ($reach !== null && $stretch['start'] < $reach) {
                        $errors->add("rules.{$stretch['index']}.start_time", __('booking.availability.overlap'));
                    }

                    $reach = max($reach ?? 0, $stretch['end']);
                }
            }
        }];
    }

    /**
     * The week as validated, by weekday and start.
     *
     * @return list<array{weekday: int, start_time: string, end_time: string}>
     */
    public function week(): array
    {
        return collect($this->validated('rules'))
            ->map(fn (array $rule) => [
                'weekday' => (int) $rule['weekday'],
                'start_time' => $rule['start_time'],
                'end_time' => $rule['end_time'],
            ])
            ->sortBy([['weekday', 'asc'], ['start_time', 'asc']])
            ->values()
            ->all();
    }
}
