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
 *
 * `channels` (since 18 Nov 2026): `{kind: {mail: bool, app: bool}}` for the
 * kinds in NotificationPreferences::KINDS, each with only the channels it can
 * take. The old switches `appointment_reminders`, `task_digest` and
 * `portal_bookings` are still taken and set every channel of their kinds.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'reminder_minutes' => ['sometimes', 'required', 'integer', Rule::in(NotificationPreferences::REMINDER_CHOICES)],
            'digest_time' => ['sometimes', 'required', 'date_format:H:i'],
            'quiet_hours' => ['sometimes', 'nullable', 'array:start,end'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i', 'different:quiet_hours.start'],
            'channels' => ['sometimes', 'array:'.implode(',', array_keys(NotificationPreferences::KINDS))],
        ];

        foreach (NotificationPreferences::legacyKeys() as $key) {
            $rules[$key] = ['sometimes', 'required', 'boolean'];
        }

        foreach (NotificationPreferences::KINDS as $kind => $channels) {
            $rules["channels.{$kind}"] = ['sometimes', 'array:'.implode(',', $channels)];

            foreach ($channels as $channel) {
                $rules["channels.{$kind}.{$channel}"] = ['sometimes', 'required', 'boolean'];
            }
        }

        return $rules;
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

            if ($preferences->wants('task_digest', NotificationPreferences::MAIL)
                && $preferences->quietHours?->covers($preferences->digestTime)) {
                $validator->errors()->add('digest_time', __('notifications.digest_in_quiet_hours'));
            }
        }];
    }

    /** The stored preferences with the sent ones laid over them. */
    public function preferences(): NotificationPreferences
    {
        $stored = $this->user()->notificationPreferences()->toArray();
        $preferences = NotificationPreferences::fromArray([
            ...$stored,
            ...$this->safe()->only(['reminder_minutes', 'digest_time', 'quiet_hours']),
        ]);

        foreach (NotificationPreferences::legacyKeys() as $key) {
            if ($this->has($key)) {
                $preferences = $preferences->withLegacySwitch($key, $this->boolean($key));
            }
        }

        $channels = $preferences->channels;

        foreach ((array) $this->validated('channels', []) as $kind => $set) {
            foreach ((array) $set as $channel => $on) {
                $channels[$kind][$channel] = (bool) $on;
            }
        }

        return NotificationPreferences::fromArray([...$preferences->toArray(), 'channels' => $channels]);
    }
}
