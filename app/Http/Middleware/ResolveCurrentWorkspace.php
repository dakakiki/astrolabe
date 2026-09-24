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
 */
class ResolveCurrentWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $workspace = $user->activeWorkspaces()->whereKey($user->current_workspace_id)->first()
            ?? $user->activeWorkspaces()->oldest('workspace_user.created_at')->first();

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
