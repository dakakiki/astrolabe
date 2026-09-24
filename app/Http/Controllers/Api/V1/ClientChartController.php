<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Services\ChartService;
use App\Http\Controllers\Concerns\ShowsNatalChart;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientChartController extends Controller
{
    use ShowsNatalChart;

    /** The client's natal chart; see ShowsNatalChart. */
    public function show(Request $request, Client $client, ChartService $charts): JsonResponse
    {
        Gate::authorize('view', $client);

        return $this->natalChart($request, $client, $charts);
    }
}
