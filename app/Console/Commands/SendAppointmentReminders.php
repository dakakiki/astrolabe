<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Scopes\WorkspaceScope;
use App\Notifications\AppointmentReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sends the appointment reminders that are due, in every workspace (runs every
 * minute from the scheduler). Each reminder is claimed with one conditional
 * update before it is queued, so a second run, a second server or a retry
 * never sends it twice (docs/spec/06, "idempotentni poslovi").
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'notifications:send-reminders';

    protected $description = 'Email astrologers the appointment reminders that are due';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        Appointment::withoutGlobalScope(WorkspaceScope::class)
            ->with('assignedUser')
            ->whereNull('reminder_sent_at')
            ->where('remind_at', '<=', $now)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('starts_at', '>', $now)
            ->chunkById(200, function (Collection $appointments) use ($now, &$sent) {
                foreach ($appointments as $appointment) {
                    $claimed = DB::table('appointments')
                        ->where('id', $appointment->getKey())
                        ->whereNull('reminder_sent_at')
                        ->where('remind_at', $appointment->remind_at)
                        ->update(['reminder_sent_at' => $now]);

                    if ($claimed === 1 && $appointment->assignedUser !== null) {
                        $appointment->assignedUser->notify(AppointmentReminder::for($appointment));
                        $sent++;
                    }
                }
            });

        $this->info(sprintf('Queued %d appointment reminder(s).', $sent));

        return self::SUCCESS;
    }
}
