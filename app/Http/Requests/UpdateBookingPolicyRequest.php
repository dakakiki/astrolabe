<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How clients book in the portal (Phase 9b, Settings → Booking; docs/spec/12,
 * "Zakazivanje (9b)"): the owner's to change, only the fields sent.
 *
 * Minutes and hours are whole numbers. The client's hours to move or cancel
 * may be null: then the client cannot do it in the portal at all. The note
 * is plain text the client reads when booking.
 */
class UpdateBookingPolicyRequest extends FormRequest
{
    /** The grid a slot may start on, in minutes. */
    public const SLOT_STEPS = [5, 10, 15, 20, 30, 45, 60, 90, 120];

    /** Two weeks. */
    public const MAX_NOTICE_MINUTES = 20160;

    public const MAX_DAYS_AHEAD = 365;

    public const MAX_BUFFER_MINUTES = 240;

    /** Two weeks. */
    public const MAX_CLIENT_HOURS = 336;

    public function authorize(): bool
    {
        return $this->user()->can('update', app(CurrentWorkspace::class)->get());
    }

    public function rules(): array
    {
        $hours = ['sometimes', 'nullable', 'integer', 'between:0,'.self::MAX_CLIENT_HOURS];
        $buffer = ['sometimes', 'required', 'integer', 'between:0,'.self::MAX_BUFFER_MINUTES];

        return [
            'enabled' => ['sometimes', 'required', 'boolean'],
            'min_notice_minutes' => ['sometimes', 'required', 'integer', 'between:0,'.self::MAX_NOTICE_MINUTES],
            'max_days_ahead' => ['sometimes', 'required', 'integer', 'between:1,'.self::MAX_DAYS_AHEAD],
            'buffer_before_minutes' => $buffer,
            'buffer_after_minutes' => $buffer,
            'slot_step_minutes' => ['sometimes', 'required', 'integer', Rule::in(self::SLOT_STEPS)],
            'requires_confirmation' => ['sometimes', 'required', 'boolean'],
            'client_reschedule_until_hours' => $hours,
            'client_cancel_until_hours' => $hours,
            'client_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
