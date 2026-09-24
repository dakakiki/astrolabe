<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\Ayanamsa;
use App\Enums\HouseSystem;
use App\Enums\TimeAccuracy;
use App\Enums\ZodiacMode;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One calculated chart for a client (later also related people), keyed by a
 * hash of its full input. Old rows stay: when a birth time is rectified, the
 * earlier versions remain for comparison (docs/spec/05).
 *
 * @property array<string, mixed> $payload
 */
class ChartCalculation extends Model
{
    use BelongsToWorkspace, ProjectsActivity;

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'julian_day_ut' => 'float',
            'house_system' => HouseSystem::class,
            'zodiac_mode' => ZodiacMode::class,
            'ayanamsa' => Ayanamsa::class,
            'time_accuracy' => TimeAccuracy::class,
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::ChartCalculated;
    }

    /**
     * A new calculation on the timeline: the first chart, or a new version after
     * the birth data, the chart settings or the engine changed.
     */
    public function activityProjection(): ?ActivityProjection
    {
        if ($this->subject_type !== (new Client)->getMorphClass()) {
            return null;
        }

        $earlier = static::withoutGlobalScopes()
            ->where('subject_type', $this->subject_type)
            ->where('subject_id', $this->subject_id)
            ->where('chart_type', $this->chart_type)
            ->where('id', '<', $this->getKey())
            ->exists();

        return new ActivityProjection(
            type: self::activityType(),
            clientId: (int) $this->subject_id,
            occurredAt: $this->calculated_at,
            metadata: [
                'chart_type' => $this->chart_type,
                'recalculated' => $earlier,
                'zodiac_mode' => $this->zodiac_mode->value,
                'ayanamsa' => $this->ayanamsa?->value,
                'house_system' => $this->house_system?->value,
                'time_accuracy' => $this->time_accuracy->value,
                'engine' => trim($this->engine_name.' '.$this->engine_version),
            ],
        );
    }
}
