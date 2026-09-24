<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant models to the current workspace. Fails closed: with no
 * current workspace a query matches nothing (or only shared rows, for models
 * that have them), so a missing tenant context can never leak data.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('workspace_id');
        $workspaceId = app(CurrentWorkspace::class)->id();
        $includeShared = $model::includesSharedRecords();

        if ($workspaceId === null) {
            $includeShared ? $builder->whereNull($column) : $builder->whereRaw('1 = 0');

            return;
        }

        if ($includeShared) {
            $builder->where(fn (Builder $query) => $query->where($column, $workspaceId)->orWhereNull($column));

            return;
        }

        $builder->where($column, $workspaceId);
    }
}
