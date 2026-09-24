<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant model (docs/spec/03, "Multi-tenancy"): scopes queries to the
 * current workspace and stamps new records with it. The workspace always comes
 * from the server-side context, never from request input.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', app(CurrentWorkspace::class)->id());
            }
        });
    }

    /**
     * Whether rows without a workspace (shared reference data) are visible to
     * every workspace. Override to return true for models such as built-in
     * astrology methods.
     */
    public static function includesSharedRecords(): bool
    {
        return false;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
