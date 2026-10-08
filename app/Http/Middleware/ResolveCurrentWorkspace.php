<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Determines the workspace an authenticated request acts in. The client never
 * names it: the user's stored current workspace is used when they are still an
 * active member of it, otherwise their first active membership.
 *
 * `workspace:optional` (only `/me`) lets an account without a practice through
 * with none — the operator's admin, which never has one (Phase 8c). Every
 * practice route uses the strict form, so the admin gets 403 there. A suspended
 * account gets 403 everywhere.
 */
class ResolveCurrentWorkspace
{
    public const SUSPENDED = 'account_suspended';

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json(['message' => __('admin.suspended'), 'code' => self::SUSPENDED], Response::HTTP_FORBIDDEN);
        }

        $workspace = $user->isAdmin() ? null : (
            $user->activeWorkspaces()->whereKey($user->current_workspace_id)->first()
                ?? $user->activeWorkspaces()->oldest('workspace_user.created_at')->first()
        );

        if ($workspace === null && $mode === 'optional') {
            return $next($request);
        }

        abort_if($workspace === null, Response::HTTP_FORBIDDEN, __('workspaces.none'));

        if ($user->current_workspace_id !== $workspace->getKey()) {
            $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();
        }

        $this->current->set($workspace);

        try {
            return $next($request);
        } finally {
            $this->current->set(null);
        }
    }
}
