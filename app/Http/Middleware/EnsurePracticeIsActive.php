<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A practice scheduled for deletion is closed: its data stays untouched until
 * the deletion is cancelled or carried out (Phase 8b). Only the routes that
 * matter then — who is signed in, cancelling, the export — are left outside
 * this middleware (routes/api.php); everything else answers 403 with a code the
 * SPA recognises.
 */
class EnsurePracticeIsActive
{
    public const CODE = 'practice_pending_deletion';

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->current->get();

        if ($workspace?->isPendingDeletion()) {
            return response()->json([
                'message' => __('workspaces.pending_deletion'),
                'code' => self::CODE,
                'deletes_at' => $workspace->deletes_at->toIso8601ZuluString(),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
