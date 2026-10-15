<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Service;
use App\Notifications\ClientAppointmentReminder;
use App\Support\Notifications\ClientAppointmentFacts;
use App\Support\Notifications\ClientRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as Rows;
use Illuminate\Support\Facades\DB;

/**
 * Sends the clients' reminders that are due, a day before each appointment
 * (Phase 9b, docs/spec/12; runs every minute from the scheduler), in every
 * practice that is not closing.
 *
 * Each due reminder is claimed with one conditional update before anything
 * else, so a second run, a second server or a retry never sends it twice
 * (docs/spec/06, "idempotentni poslovi"). Whether it goes is decided then
 * (ClientReminders): only to a client with a usable portal link whose account
 * has reminders on. A reminder nobody receives is claimed all the same — it is
 * done with, and the due ones stay few.
 */
class SendClientReminders extends Command
{
    protected $signature = 'notifications:send-client-reminders';

    protected $description = 'Email clients with portal access the appointment reminders that are due';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        Appointment::withoutGlobalScope(WorkspaceScope::class)
            ->whereNull('client_reminder_sent_at')
            ->where('client_remind_at', '<=', $now)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('starts_at', '>', $now)
            // A practice scheduled for deletion is closed; its reminders are not sent.
            ->whereHas('workspace', fn ($query) => $query->whereNull('deletes_at'))
            ->chunkById(200, function (Collection $appointments) use ($now, &$sent) {
                $recipients = ClientRecipient::forMany($appointments);
                $policies = $this->policies($appointments);
                $services = $this->services($appointments);

                foreach ($appointments as $appointment) {
                    $claimed = DB::table('appointments')
                        ->where('id', $appointment->getKey())
                        ->whereNull('client_reminder_sent_at')
                        ->where('client_remind_at', $appointment->client_remind_at)
                        ->update(['client_reminder_sent_at' => $now]);

                    $access = $recipients->get(ClientRecipient::key((int) $appointment->workspace_id, (int) $appointment->client_id));

                    if ($claimed !== 1 || $access?->portalUser === null || ! $access->portalUser->appointment_reminders) {
                        continue;
                    }

                    $access->portalUser->notify(new ClientAppointmentReminder(ClientAppointmentFacts::of(
                        $appointment,
                        $access->workspace,
                        $policies->get($appointment->workspace_id),
                        $appointment->service_id === null ? null : $services->get($appointment->service_id),
                    )));
                    $sent++;
                }
            });

        $this->info(sprintf('Queued %d client reminder(s).', $sent));

        return self::SUCCESS;
    }

    /**
     * Each practice's booking policy (until when the client may still change it).
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return Rows<int, BookingPolicy>
     */
    private function policies(Collection $appointments): Rows
    {
        return BookingPolicy::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereIn('workspace_id', $appointments->pluck('workspace_id')->unique()->values())
            ->get()
            ->keyBy('workspace_id');
    }

    /**
     * Service names by id.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return Rows<int, string>
     */
    private function services(Collection $appointments): Rows
    {
        $ids = $appointments->pluck('service_id')->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Service::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereIn('id', $ids)
            ->pluck('name', 'id');
    }
}
