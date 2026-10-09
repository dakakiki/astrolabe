<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Portal\PortalAppointmentResource;
use App\Support\Portal\PortalContext;
use App\Support\Portal\PortalSessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal → Home (docs/spec/12): the practice, the next appointment and how
 * much was shared since the client last looked.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, PortalContext $context): JsonResponse
    {
        $upcoming = AppointmentController::forClient($context)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('ends_at', '>', now());

        $next = (clone $upcoming)->with('service:id,name')->orderBy('starts_at')->first();
        $seen = $context->access()->shared_seen_at;
        $changed = fn (Builder $query) => $seen === null ? $query : $query->where('updated_at', '>', $seen);

        return response()->json(['data' => [
            'practice' => PortalSessionState::practice($context->workspace()),
            'next_appointment' => $next ? PortalAppointmentResource::make($next)->resolve($request) : null,
            'upcoming_count' => $upcoming->count(),
            'new_shared' => $changed(SharedController::notes($context))->count()
                + $changed(SharedController::attachments($context))->count(),
            'shared_total' => SharedController::notes($context)->count() + SharedController::attachments($context)->count(),
        ]]);
    }
}
