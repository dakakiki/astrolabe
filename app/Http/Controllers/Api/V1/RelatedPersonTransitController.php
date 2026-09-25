<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Services\TransitService;
use App\Http\Controllers\Concerns\ShowsTransits;
use App\Http\Controllers\Controller;
use App\Models\RelatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RelatedPersonTransitController extends Controller
{
    use ShowsTransits;

    /** Transits to the related person's natal chart; see ShowsTransits. */
    public function show(Request $request, RelatedPerson $relatedPerson, TransitService $transits): JsonResponse
    {
        Gate::authorize('view', $relatedPerson);

        return $this->transitsFor($request, $relatedPerson, $transits);
    }
}
