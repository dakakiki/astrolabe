<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Enums\LocationType;
use App\Enums\MembershipStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or change (PATCH, only the fields sent) an appointment.
 *
 * `starts_at` is local wall-clock time ("2026-10-05T15:00") in `timezone`,
 * which defaults to the astrologer's zone. The length is `duration_minutes`,
 * or the service's for a new appointment. The client is chosen once.
 * Cancelling has its own endpoint, since it needs a reason.
 */
class SaveAppointmentRequest extends FormRequest
{
    public const MAX_DURATION = 1440;

    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment
            ? $this->user()->can('update', $appointment)
            : $this->user()->can('create', Appointment::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $creating = ! $this->route('appointment') instanceof Appointment;
        $sometimes = $creating ? [] : ['sometimes'];

        return [
            'client_id' => $creating
                ? ['required', 'integer', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')]
                : ['prohibited'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('workspace_id', $workspaceId)],
            'starts_at' => [...$sometimes, 'required', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'duration_minutes' => [
                'nullable',
                'integer',
                'min:5',
                'max:'.self::MAX_DURATION,
                // A new appointment needs a length: its own or its service's.
                Rule::requiredIf($creating && ! $this->filled('service_id')),
            ],
            'status' => $creating
                ? ['prohibited']
                : ['sometimes', Rule::enum(AppointmentStatus::class)->except([AppointmentStatus::Cancelled])],
            'location_type' => ['nullable', Rule::enum(LocationType::class)->only([LocationType::Online, LocationType::InPerson])],
            'location_details' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Minutes before the start; null = no reminder, left out = the astrologer's usual one.
            'reminder_minutes' => ['nullable', 'integer', 'min:5', 'max:'.NotificationPreferences::MAX_REMINDER_MINUTES],
            'assigned_user_id' => [
                ...($creating ? ['nullable'] : ['sometimes', 'required']),
                'integer',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', MembershipStatus::Active->value),
            ],
            'allow_overlap' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * An inactive service stays on an appointment but is not chosen anew.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $serviceId = $this->input('service_id');
            $appointment = $this->route('appointment');
            $unchanged = $appointment instanceof Appointment && (int) $appointment->service_id === (int) $serviceId;

            if ($serviceId !== null && ! $validator->errors()->has('service_id') && ! $unchanged
                && ! Service::query()->whereKey($serviceId)->value('is_active')) {
                $validator->errors()->add('service_id', __('consultations.service_inactive'));
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function appointmentAttributes(): array
    {
        return collect($this->validated())->except('allow_overlap')->all();
    }

    public function messages(): array
    {
        return [
            'client_id.prohibited' => __('appointments.client_fixed'),
            'status.prohibited' => __('appointments.status_on_create'),
        ];
    }
}
