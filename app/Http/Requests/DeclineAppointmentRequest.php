<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning down a request booked in the portal (Phase 9b). The reason is
 * optional and written for the client — it goes to them with the answer.
 * Whether the appointment still waits for an answer is the action's to
 * check (409 `not_requested`).
 */
class DeclineAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('appointment'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
