/**
 * Email notifications to the astrologer (docs/spec/10, "Notifikacije"): the
 * reminder before an appointment and the morning task email. The server plans
 * when a reminder goes out (`remind_at`, moved out of quiet hours); this words
 * lead times and says what an appointment's reminder is doing.
 */

/** Lead times offered for reminders, in minutes (NotificationPreferences::REMINDER_CHOICES). */
export const REMINDER_CHOICES = [15, 30, 60, 120, 180, 360, 720, 1440, 2880];

const MINUTE = 60_000;

/** A lead time in its largest whole unit, for wording: { unit: 'days' | 'hours' | 'minutes', count }. */
export function leadTime(minutes) {
    if (minutes % 1440 === 0) return { unit: 'days', count: minutes / 1440 };
    if (minutes % 60 === 0) return { unit: 'hours', count: minutes / 60 };

    return { unit: 'minutes', count: minutes };
}

/** The choices for a form, keeping a lead time set elsewhere (the API takes any). */
export function reminderChoices(current = null) {
    const values = new Set(REMINDER_CHOICES);
    if (Number.isInteger(current) && current > 0) values.add(current);

    return [...values].sort((a, b) => a - b);
}

/**
 * What an appointment's reminder is doing, or null when there is nothing to say
 * (held, cancelled or missed without one having been sent):
 *
 * - `sent`: it went out at `at`;
 * - `planned`: it goes out at `at`; `shifted` when quiet hours moved it;
 * - `none`: no reminder was chosen;
 * - `paused`: the astrologer switched reminders off (known only for one's own);
 * - `passed`: its time had gone by when the appointment was booked or moved.
 *
 * `preferences` are the astrologer's own, or null when someone else runs it.
 */
export function reminderState(appointment, preferences = null) {
    if (appointment.reminder_sent_at) return { state: 'sent', at: appointment.reminder_sent_at };
    if (appointment.status !== 'scheduled') return null;
    if (appointment.reminder_minutes == null) return { state: 'none', at: null };

    if (appointment.remind_at) {
        const due = new Date(appointment.starts_at).getTime() - appointment.reminder_minutes * MINUTE;

        return {
            state: 'planned',
            at: appointment.remind_at,
            shifted: new Date(appointment.remind_at).getTime() !== due,
        };
    }

    if (preferences?.appointment_reminders === false) return { state: 'paused', at: null };

    return { state: 'passed', at: null };
}

/** Quiet hours as the form edits them: a switch and two times (the API takes null for off). */
export function quietHoursForm(preferences) {
    const quiet = preferences?.quiet_hours;

    return { enabled: Boolean(quiet), start: quiet?.start ?? '22:00', end: quiet?.end ?? '08:00' };
}

/** The preferences to send from the settings form. */
export function preferencesPayload(form) {
    return {
        appointment_reminders: form.appointment_reminders,
        reminder_minutes: form.reminder_minutes,
        task_digest: form.task_digest,
        digest_time: form.digest_time,
        quiet_hours: form.quiet_enabled ? { start: form.quiet_start, end: form.quiet_end } : null,
    };
}
