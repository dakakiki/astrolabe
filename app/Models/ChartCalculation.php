<?php

namespace App\Models;

use App\Enums\Ayanamsa;
use App\Enums\HouseSystem;
use App\Enums\TimeAccuracy;
use App\Enums\ZodiacMode;
use App\Models\Concerns\BelongsToWorkspace;
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
    use BelongsToWorkspace;

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
}
