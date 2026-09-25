<?php

namespace App\Support\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * When an appointment's reminder goes out (docs/spec/10, "Notifikacije").
 *
 * The appointment keeps its lead time (`reminder_minutes`, null = none); the
 * moment it works out to is stored in `remind_at`, and the scheduler sends
 * what is due (`notifications:send-reminders`). The moment is:
 *
 * - the start minus the lead time, for a scheduled appointment whose
 *   astrologer has reminders switched on;
 * - moved out of the astrologer's quiet hours: to when they end, or — if the
 *   appointment starts by then — to just before they began;
 * - none when it has already passed (booking something for this afternoon
 *   does not send a reminder about it).
 *
 * Moving the appointment, changing its lead time, status or astrologer plans it
 * again (Appointment::booted); a reminder planned for a new moment is unsent.
 * Changing one's preferences or zone plans the unsent upcoming ones again.
 */
final class AppointmentReminders
{
    public static function at(Appointment $appointment, ?User $astrologer, CarbonInterface $now): ?CarbonImmutable
    {
        if ($appointment->status !== AppointmentStatus::Scheduled
            || $appointment->reminder_minutes === null
            || $astrologer === null) {
            return null;
        }

        $preferences = $astrologer->notificationPreferences();

        if (! $preferences->appointmentReminders) {
            return null;
        }

        $start = CarbonImmutable::instance($appointment->starts_at)->utc();
        $moment = $start->subMinutes((int) $appointment->reminder_minutes);
        $moment = $preferences->quietHours?->shift($moment, $astrologer->timezone ?: 'UTC', $start) ?? $moment;

        return $moment > $now ? $moment : null;
    }

    /** Sets `remind_at` on an appointment about to be saved; a new moment means a new, unsent reminder. */
    public static function plan(Appointment $appointment): void
    {
        $at = self::at($appointment, self::astrologer($appointment), CarbonImmutable::now());
        $changed = $at?->toIso8601ZuluString() !== $appointment->remind_at?->toIso8601ZuluString();

        $appointment->remind_at = $at;

        if ($at !== null && $changed) {
            $appointment->reminder_sent_at = null;
        }
    }

    /** After a change to someone's preferences or zone: their unsent upcoming reminders, planned again. */
    public static function replan(User $astrologer): void
    {
        $now = CarbonImmutable::now();

        // Every workspace the person works in; the tenant scope would see only one.
        Appointment::withoutGlobalScope(WorkspaceScope::class)
            ->where('assigned_user_id', $astrologer->getKey())
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('starts_at', '>', $now)
            ->whereNotNull('reminder_minutes')
            ->whereNull('reminder_sent_at')
            ->each(function (Appointment $appointment) use ($astrologer, $now) {
                DB::table('appointments')
                    ->where('id', $appointment->getKey())
                    ->update(['remind_at' => self::at($appointment, $astrologer, $now)]);
            });
    }

    private static function astrologer(Appointment $appointment): ?User
    {
        if ($appointment->assigned_user_id === null) {
            return null;
        }

        $loaded = $appointment->relationLoaded('assignedUser') ? $appointment->assignedUser : null;

        return $loaded !== null && $loaded->getKey() === (int) $appointment->assigned_user_id
            ? $loaded
            : User::query()->find($appointment->assigned_user_id);
    }
}
