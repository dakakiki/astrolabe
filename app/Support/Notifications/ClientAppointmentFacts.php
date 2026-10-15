<?php

namespace App\Support\Notifications;

use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * What a client's email about one appointment may say (docs/spec/12, "Mejlovi
 * (9b)"): the practice by the name clients see, the service, online or in
 * person, the time, and until when the portal lets them move or cancel it.
 * Never notes, the client's own message or anything the practice keeps internal.
 *
 * Read once when the email is queued and kept as plain values: a queue worker
 * has no current practice, so tenant models would come back empty there. The
 * client's own zone is applied when the email is written (it may change meanwhile).
 */
final readonly class ClientAppointmentFacts
{
    public function __construct(
        public int $appointmentId,
        public int $workspaceId,
        public int $clientId,
        public string $practice,
        public string $practiceZone,
        public ?string $service,
        public string $location,
        public string $status,
        public string $startsAt,
        public string $endsAt,
        public ?string $rescheduleUntil = null,
        public ?string $cancelUntil = null,
    ) {}

    /**
     * Reads the service's name and the practice's booking policy without the
     * tenant scope (listeners may run outside the practice's request).
     */
    public static function read(Appointment $appointment, Workspace $workspace): self
    {
        $policy = BookingPolicy::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->first();

        return self::of($appointment, $workspace, $policy, self::serviceName($appointment));
    }

    /** From values already at hand (the reminder run reads them for many at once). */
    public static function of(Appointment $appointment, Workspace $workspace, ?BookingPolicy $policy, ?string $service): self
    {
        $policy ??= new BookingPolicy;

        return new self(
            appointmentId: (int) $appointment->getKey(),
            workspaceId: (int) $workspace->getKey(),
            clientId: (int) $appointment->client_id,
            practice: $workspace->portalName(),
            practiceZone: $workspace->timezone ?: 'UTC',
            service: $service,
            location: $appointment->location_type->value,
            status: $appointment->status->value,
            startsAt: $appointment->starts_at->toIso8601ZuluString(),
            endsAt: $appointment->ends_at->toIso8601ZuluString(),
            rescheduleUntil: $policy->rescheduleDeadline($appointment)?->toIso8601ZuluString(),
            cancelUntil: $policy->cancelDeadline($appointment)?->toIso8601ZuluString(),
        );
    }

    public static function serviceName(Appointment $appointment): ?string
    {
        if ($appointment->service_id === null) {
            return null;
        }

        if ($appointment->relationLoaded('service')) {
            return $appointment->service?->name;
        }

        return Service::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereKey($appointment->service_id)
            ->value('name');
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->startsAt);
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->endsAt);
    }
}
