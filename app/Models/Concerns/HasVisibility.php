<?php

namespace App\Models\Concerns;

use App\Enums\Visibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Private content is seen only by its author; team and shared content by every
 * member of the workspace (docs/spec/02). The workspace itself is already
 * enforced by the tenant scope.
 *
 * @mixin Model
 */
trait HasVisibility
{
    /** The column naming who wrote or uploaded the row. */
    abstract public static function ownerColumn(): string;

    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->where($this->qualifyColumn('visibility'), '!=', Visibility::Private->value)
            ->orWhere($this->qualifyColumn(static::ownerColumn()), $user->getKey()));
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->getAttribute('visibility') !== Visibility::Private
            || $this->getAttribute(static::ownerColumn()) === $user->getKey();
    }
}
