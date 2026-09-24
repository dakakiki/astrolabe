<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cancelling needs a reason (docs/spec/10), and only a scheduled appointment
 * can be cancelled: one that took place, or was missed, stays as it was.
 */
class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('appointment'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->route('appointment')->status !== AppointmentStatus::Scheduled) {
                $validator->errors()->add('status', __('appointments.not_cancellable'));
            }
        }];
    }
}
