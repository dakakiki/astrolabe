<?php

namespace App\Models\Concerns;

use App\Enums\ActivityType;
use App\Support\Activity\ActivityProjection;
use App\Support\Activity\ActivityProjector;
use Illuminate\Database\Eloquent\Model;

/**
 * For rows that appear on a client's timeline as one entry each (docs/spec/05,
 * "activity_events"). The entry follows the row: saving updates it, deleting —
 * soft or not — removes it, restoring brings it back.
 *
 * @mixin Model
 */
trait ProjectsActivity
{
    public static function bootProjectsActivity(): void
    {
        // Restoring a soft-deleted row saves it, so "saved" covers restores too.
        static::saved(fn (Model $model) => app(ActivityProjector::class)->sync($model));
        static::deleted(fn (Model $model) => app(ActivityProjector::class)->sync($model));
    }

    abstract public static function activityType(): ActivityType;

    /** The timeline entry for this row, or null when it should have none. */
    abstract public function activityProjection(): ?ActivityProjection;
}
