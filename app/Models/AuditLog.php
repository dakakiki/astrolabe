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
 * Not tenant-scoped on purpose: sign-ins belong to a person, not a practice.
 * Read only by the operator's admin (Phase 8c); astrologers do not see it.
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
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Failed sign-ins, lockouts and the like (AuditEvent::warnings()).
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeWarnings(Builder $query): void
    {
        $query->whereIn('event', array_map(fn (AuditEvent $event) => $event->value, AuditEvent::warnings()));
    }

    public function isWarning(): bool
    {
        return in_array($this->event, AuditEvent::warnings(), true);
    }
}
