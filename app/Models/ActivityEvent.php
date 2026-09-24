<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\Visibility;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry on a client's timeline (docs/spec/05). Written only by
 * ActivityProjector and ActivityLog.
 *
 * @property ActivityType $event_type
 * @property Visibility|null $visibility
 * @property array<string, mixed>|null $metadata
 */
class ActivityEvent extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'event_type' => ActivityType::class,
            'visibility' => Visibility::class,
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Entries copied from private notes and files show only to their author.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('visibility')
            ->orWhere('visibility', '!=', Visibility::Private->value)
            ->orWhere('created_by', $user->getKey()));
    }
}
