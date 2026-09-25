<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Services\TransitService;
use App\Http\Controllers\Concerns\ShowsTransits;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientTransitController extends Controller
{
    use ShowsTransits;

    /** Transits to the client's natal chart; see ShowsTransits. */
    public function show(Request $request, Client $client, TransitService $transits): JsonResponse
    {
        Gate::authorize('view', $client);

        return $this->transitsFor($request, $client, $transits);
    }
}
