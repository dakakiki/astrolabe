<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\LocationType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use App\Support\Notifications\AppointmentReminders;
use App\Support\Notifications\ClientReminders;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Time reserved in the calendar for a client (docs/spec/10). It organises
 * time; what was said is a consultation, recorded from it and linked to it.
 * Moving an appointment changes this row and leaves an entry on the timeline;
 * cancelling keeps it with a reason.
 *
 * The astrologer gets an email reminder `reminder_minutes` before it (null =
 * none); `remind_at` is when that works out to (AppointmentReminders).
 *
 * @property CarbonImmutable $starts_at UTC
 * @property CarbonImmutable $ends_at UTC
 * @property AppointmentStatus $status
 * @property LocationType $location_type
 * @property int|null $reminder_minutes
 * @property CarbonImmutable|null $remind_at UTC
 * @property CarbonImmutable|null $reminder_sent_at UTC
 * @property BookingSource $booking_source
 * @property AppointmentActor|null $cancelled_by
 * @property CarbonImmutable|null $declined_at UTC
 * @property CarbonImmutable|null $client_remind_at UTC
 * @property CarbonImmutable|null $client_reminder_sent_at UTC
 *
 * Booked in the portal (Phase 9b): `booking_source` portal, `portal_user_id` and the
 * client's message (`client_note`, for the astrologer's eyes only). A practice that
 * confirms bookings by hand keeps them `requested` until it confirms or declines;
 * a declined request is cancelled with `declined_at`, and its reason goes to the client.
 * The client's own reminder a day before is `client_remind_at` (ClientReminders).
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToWorkspace, HasFactory, ProjectsActivity, SoftDeletes;

    /** Set by the actions (SaveAppointment, CancelAppointment), never mass-assigned from a request. */
    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
            'location_type' => LocationType::class,
            'booking_source' => BookingSource::class,
            'cancelled_at' => 'immutable_datetime',
            'reminder_minutes' => 'integer',
            'remind_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'cancelled_by' => AppointmentActor::class,
            'declined_at' => 'immutable_datetime',
            'client_remind_at' => 'immutable_datetime',
            'client_reminder_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * The reminders follow the appointment: moving, cancelling, a new lead
     * time or another astrologer plans them again.
     */
    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment) {
            if (! $appointment->exists
                || $appointment->isDirty(['starts_at', 'reminder_minutes', 'status', 'assigned_user_id'])) {
                AppointmentReminders::plan($appointment);
            }

            if (! $appointment->exists || $appointment->isDirty(['starts_at', 'status'])) {
                ClientReminders::plan($appointment);
            }
        });
    }

    /**
     * The client's portal account that booked it (Phase 9b); null for the astrologer's own.
     *
     * @return BelongsTo<PortalUser, $this>
     */
    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    /** A request booked in the portal that the practice turned down (Phase 9b). */
    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * The consultation recorded from this appointment; at most one.
     *
     * @return HasOne<Consultation, $this>
     */
    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    /**
     * Money paid for this appointment: deposits before it, which also belong
     * to the consultation once it is recorded (Phase 7b).
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** The start as it was entered, in its own zone. */
    public function localStart(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    /**
     * Appointments that take up time between the two moments (touching ends do
     * not overlap: 10:00–11:00 and 11:00–12:00 are both fine).
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::Appointment;
    }

    /**
     * The appointment on the client's timeline, at its own time. Once a
     * consultation has been recorded from it, the consultation stands for the
     * session and the appointment steps aside.
     */
    public function activityProjection(): ?ActivityProjection
    {
        // No workspace scope outside a request (activity:rebuild): the rows are this appointment's own.
        $recorded = Consultation::withoutGlobalScopes()
            ->where('appointment_id', $this->getKey())
            ->whereNull('deleted_at')
            ->exists();

        if ($recorded) {
            return null;
        }

        $service = $this->service_id === null ? null : ($this->relationLoaded('service')
            ? $this->service
            : $this->service()->withoutGlobalScopes()->first());

        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->starts_at,
            createdBy: $this->created_by,
            summary: $service?->name,
            metadata: [
                'status' => $this->status->value,
                'service' => $service?->name,
                'duration_minutes' => $this->durationMinutes(),
                'timezone' => $this->timezone,
                'location_type' => $this->location_type->value,
                'booking_source' => $this->booking_source?->value,
            ],
        );
    }
}
