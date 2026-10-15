<?php

namespace App\Models;

use App\Enums\AvailabilityKind;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time off or extra time on top of an astrologer's week (Phase 9b): a stretch in
 * UTC with the zone it was entered in. Whole days (`all_day`) run from midnight
 * of the first day to midnight after the last, on that zone's clock. The note is
 * the astrologer's own; clients never see why a time is not offered.
 *
 * @property AvailabilityKind $kind
 * @property CarbonImmutable $starts_at UTC
 * @property CarbonImmutable $ends_at UTC
 */
#[Fillable(['user_id', 'kind', 'starts_at', 'ends_at', 'timezone', 'all_day', 'note'])]
class AvailabilityException extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'kind' => AvailabilityKind::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'all_day' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
