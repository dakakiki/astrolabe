<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the audit log, written by App\Support\Audit\Audit. Rows are
 * only ever added: nothing in the application changes or rebuilds them.
 *
 * Not tenant-scoped on purpose: sign-ins belong to a person, not a practice,
 * and are read by `user_id`.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'properties' => 'array',
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
     * The account events a person may see about themselves.
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeAccountOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey())
            ->whereIn('event', array_map(fn (AuditEvent $event) => $event->value, AuditEvent::account()));
    }
}
