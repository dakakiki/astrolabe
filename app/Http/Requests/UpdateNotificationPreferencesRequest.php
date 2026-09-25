<?php

namespace App\Http\Requests;

use App\Support\Notifications\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The signed-in person's own notification preferences (Settings →
 * Notifications). Only the keys sent change; times are "HH:MM" on the
 * person's own clock, and `quiet_hours: null` switches quiet hours off.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_reminders' => ['sometimes', 'required', 'boolean'],
            'reminder_minutes' => ['sometimes', 'required', 'integer', Rule::in(NotificationPreferences::REMINDER_CHOICES)],
            'task_digest' => ['sometimes', 'required', 'boolean'],
            'digest_time' => ['sometimes', 'required', 'date_format:H:i'],
            'quiet_hours' => ['sometimes', 'nullable', 'array:start,end'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i', 'different:quiet_hours.start'],
        ];
    }

    /**
     * The morning email cannot be set for a time the person asked to be left alone.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $preferences = $this->preferences();

            if ($preferences->taskDigest && $preferences->quietHours?->covers($preferences->digestTime)) {
                $validator->errors()->add('digest_time', __('notifications.digest_in_quiet_hours'));
            }
        }];
    }

    /** The stored preferences with the sent ones laid over them. */
    public function preferences(): NotificationPreferences
    {
        return NotificationPreferences::fromArray([
            ...$this->user()->notificationPreferences()->toArray(),
            ...$this->safe()->only(array_keys(NotificationPreferences::defaults()->toArray())),
        ]);
    }
}
