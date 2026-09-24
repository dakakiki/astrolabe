<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\ConsultationStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use App\Support\Activity\ActivityProjector;
use Carbon\CarbonImmutable;
use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The professional record of one session with a client (docs/spec/02):
 * topics, internal notes, the summary meant for the client, next steps, the
 * methods used and, optionally, the chart as it stood that day.
 *
 * The client is set once, on creation: notes, files and the chart snapshot
 * all belong to that client.
 *
 * @property ConsultationStatus $status
 * @property CarbonImmutable|null $starts_at UTC
 */
#[Fillable([
    'service_id', 'title', 'starts_at', 'timezone', 'duration_minutes', 'status',
    'topics', 'internal_notes', 'client_summary', 'next_steps',
])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use BelongsToWorkspace, HasFactory, ProjectsActivity, SoftDeletes;

    protected static function booted(): void
    {
        // The appointment's timeline entry gives way to the consultation recorded
        // from it, and comes back if the consultation is deleted.
        $syncAppointment = function (Consultation $consultation) {
            $ids = array_filter([$consultation->appointment_id, $consultation->getOriginal('appointment_id')]);

            Appointment::withoutGlobalScopes()->whereKey($ids)->get()
                ->each(fn (Appointment $appointment) => app(ActivityProjector::class)->sync($appointment));
        };

        static::saved($syncAppointment);
        static::deleted($syncAppointment);
    }

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'starts_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The calendar appointment this consultation was recorded from, if any.
     *
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The chart snapshot attached to this consultation.
     *
     * @return BelongsTo<ChartCalculation, $this>
     */
    public function chart(): BelongsTo
    {
        return $this->belongsTo(ChartCalculation::class, 'chart_calculation_id');
    }

    /**
     * @return BelongsToMany<AstrologyMethod, $this>
     */
    public function astrologyMethods(): BelongsToMany
    {
        return $this->belongsToMany(AstrologyMethod::class, 'consultation_astrology_method');
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** The start as it was entered, in its own zone. */
    public function localStart(): ?CarbonImmutable
    {
        return $this->starts_at?->setTimezone($this->timezone ?? 'UTC');
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::Consultation;
    }

    public function activityProjection(): ActivityProjection
    {
        // Projections are kept in step outside a request too (activity:rebuild, jobs),
        // where no workspace scope applies; the service is the consultation's own.
        $service = $this->service_id === null ? null : ($this->relationLoaded('service')
            ? $this->service
            : $this->service()->withoutGlobalScopes()->first());

        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->starts_at ?? $this->created_at,
            createdBy: $this->created_by,
            summary: $this->title ?? $service?->name,
            metadata: [
                'status' => $this->status->value,
                'title' => $this->title,
                'service' => $service?->name,
                'duration_minutes' => $this->duration_minutes,
                'timezone' => $this->timezone,
                'has_chart' => $this->chart_calculation_id !== null,
                'topics' => $this->topics === null ? null : Str::limit(trim(preg_replace('/\s+/u', ' ', $this->topics) ?? ''), 160),
            ],
        );
    }
}
