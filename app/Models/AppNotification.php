<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification in the app — the bell in the header (docs/spec/09,
 * "Obaveštenja"): what happened (`kind`), the ids and times it is about
 * (`data`), and whether the person has seen it. Written by InAppChannel.
 *
 * Not tenant-scoped on its own: a person sees their own, in the practice they
 * have open, and the ones about their account (`workspace_id` null). Names of
 * clients are looked up when the list is shown, inside the practice.
 *
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The person's own, in the practice they have open and about their account.
     *
     * @param  Builder<AppNotification>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, ?int $workspaceId): void
    {
        $query->where('user_id', $user->getKey())
            ->where(fn (Builder $query) => $query->whereNull('workspace_id')
                ->when($workspaceId !== null, fn (Builder $query) => $query->orWhere('workspace_id', $workspaceId)));
    }
}
