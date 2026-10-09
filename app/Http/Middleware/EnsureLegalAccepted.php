<?php

namespace App\Http\Middleware;

use App\Support\Legal\LegalDocuments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The practice opens only once its member has accepted the Terms of Service
 * and the Data Processing Agreement in force (Phase 8c). When a new version is
 * published, every practice route answers 403 with a code the SPA recognises
 * until the person accepts it. The routes left outside (routes/api.php) are the
 * same ones a closing practice keeps: who is signed in, the export and
 * deleting the practice — someone who does not agree can still take their
 * data and leave.
 */
class EnsureLegalAccepted
{
    public const CODE = 'legal_acceptance_required';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $pending = $user ? LegalDocuments::pendingFor($user) : [];

        if ($pending !== []) {
            return response()->json([
                'message' => __('legal.pending'),
                'code' => self::CODE,
                'documents' => $pending,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
