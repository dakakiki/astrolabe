<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Recent security activity" in Settings → Security: the person's own sign-ins,
 * failed attempts and account changes, newest first. Only their own — never
 * another member's, and none of the practice's data events.
 */
class SecurityActivityController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $entries = AuditLog::query()
            ->accountOf($request->user())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return AuditLogResource::collection($entries);
    }
}
