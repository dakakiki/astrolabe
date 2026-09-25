<?php

namespace App\Models\Concerns;

use App\Enums\ActivityType;
use App\Support\Activity\ActivityProjection;
use App\Support\Activity\ActivityProjector;
use Illuminate\Database\Eloquent\Model;

/**
 * For rows that appear on a client's timeline (docs/spec/05, "activity_events").
 * The entries follow the row: saving updates them, deleting — soft or not —
 * removes them, restoring brings them back.
 *
 * Most rows keep one entry (`activityType()` / `activityProjection()`); a row
 * that keeps more — a task and, once done, its completion — lists them in
 * `projectedActivityTypes()` and `activityProjections()`.
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

    /**
     * Every entry type this row may keep on the timeline.
     *
     * @return list<ActivityType>
     */
    public static function projectedActivityTypes(): array
    {
        return [static::activityType()];
    }

    /**
     * The entries this row should have now, by type value; a missing or null
     * one is removed.
     *
     * @return array<string, ActivityProjection|null>
     */
    public function activityProjections(): array
    {
        return [static::activityType()->value => $this->activityProjection()];
    }
}
