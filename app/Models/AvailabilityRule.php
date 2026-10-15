<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of an astrologer's working week (Phase 9b, Settings → Availability):
 * a weekday and wall-clock times in the zone the week was entered in, so the
 * hours stay "9 to 5" across a change of the clocks. "24:00" ends at midnight.
 *
 * @property int $weekday 1 = Monday … 7 = Sunday
 * @property string $start_time "HH:MM"
 * @property string $end_time "HH:MM"
 */
#[Fillable(['user_id', 'weekday', 'start_time', 'end_time', 'timezone'])]
class AvailabilityRule extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'user_id' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The database keeps seconds ("09:00:00"); the app speaks in minutes ("09:00"). */
    protected function startTime(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => $value === null ? null : substr($value, 0, 5));
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => $value === null ? null : substr($value, 0, 5));
    }

    public function startMinute(): int
    {
        return self::minutes($this->start_time);
    }

    public function endMinute(): int
    {
        return self::minutes($this->end_time);
    }

    /** "09:30" → 570; "24:00" → 1440. */
    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
