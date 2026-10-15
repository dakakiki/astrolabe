<?php

namespace App\Support\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * When the client's own reminder goes out (Phase 9b): a day before a scheduled
 * appointment, planned as a UTC moment in `client_remind_at` like the
 * astrologer's (AppointmentReminders). Whether it is sent is decided when it is
 * due — only to a client who can use the portal and has not switched reminders
 * off in Portal → Profile — so a portal link made or revoked later needs no replanning.
 *
 * A moment already past is not planned (booking for tomorrow morning does not
 * send a reminder about it); moving the appointment plans it again, cancelling clears it.
 */
final class ClientReminders
{
    public const LEAD_MINUTES = 1440;

    public static function at(Appointment $appointment, CarbonInterface $now): ?CarbonImmutable
    {
        if ($appointment->status !== AppointmentStatus::Scheduled || $appointment->starts_at === null) {
            return null;
        }

        $moment = CarbonImmutable::instance($appointment->starts_at)->utc()->subMinutes(self::LEAD_MINUTES);

        return $moment > $now ? $moment : null;
    }

    /**
     * Plans the reminder for scheduled appointments saved before there was one
     * (the Phase 9b migration runs it once): the moment `at()` gives, nothing
     * where that has passed. Every practice at once, straight on the table —
     * no tenant scope, no model events, `updated_at` untouched. Only empty
     * moments are filled; returns how many.
     */
    public static function backfill(CarbonInterface $now): int
    {
        $lead = self::LEAD_MINUTES;

        return DB::table('appointments')
            ->where('status', AppointmentStatus::Scheduled->value)
            ->whereNull('deleted_at')
            ->whereNull('client_remind_at')
            ->where('starts_at', '>', CarbonImmutable::instance($now)->utc()->addMinutes($lead)->format('Y-m-d H:i:s'))
            ->update(['client_remind_at' => DB::raw("DATE_SUB(starts_at, INTERVAL {$lead} MINUTE)")]);
    }

    /** Sets `client_remind_at` on an appointment about to be saved; a new moment is unsent. */
    public static function plan(Appointment $appointment): void
    {
        $at = self::at($appointment, CarbonImmutable::now());
        $changed = $at?->toIso8601ZuluString() !== $appointment->client_remind_at?->toIso8601ZuluString();

        $appointment->client_remind_at = $at;

        if ($at !== null && $changed) {
            $appointment->client_reminder_sent_at = null;
        }
    }
}
