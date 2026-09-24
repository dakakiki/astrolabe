<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\ConsultationStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
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
    'title', 'starts_at', 'timezone', 'duration_minutes', 'status',
    'topics', 'internal_notes', 'client_summary', 'next_steps',
])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use BelongsToWorkspace, HasFactory, ProjectsActivity, SoftDeletes;

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
        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->starts_at ?? $this->created_at,
            createdBy: $this->created_by,
            summary: $this->title,
            metadata: [
                'status' => $this->status->value,
                'title' => $this->title,
                'duration_minutes' => $this->duration_minutes,
                'timezone' => $this->timezone,
                'has_chart' => $this->chart_calculation_id !== null,
                'topics' => $this->topics === null ? null : Str::limit(trim(preg_replace('/\s+/u', ' ', $this->topics) ?? ''), 160),
            ],
        );
    }
}
