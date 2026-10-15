<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cancelling needs a reason (docs/spec/10), and only a scheduled appointment
 * can be cancelled: one that took place, or was missed, stays as it was.
 *
 * Phase 9b: a request booked in the portal is declined instead (its own
 * endpoint, with a reason for the client). The reason here stays internal;
 * `notify_client` (default yes) says whether a client who can use the portal
 * gets an email that the appointment was cancelled.
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
            'notify_client' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $status = $this->route('appointment')->status;

            if ($status !== AppointmentStatus::Scheduled) {
                $validator->errors()->add('status', __($status === AppointmentStatus::Requested
                    ? 'appointments.decline_instead'
                    : 'appointments.not_cancellable'));
            }
        }];
    }

    /** Whether the client hears about it by email (Phase 9b); yes unless the request says no. */
    public function notifyClient(): bool
    {
        return $this->boolean('notify_client', true);
    }
}
