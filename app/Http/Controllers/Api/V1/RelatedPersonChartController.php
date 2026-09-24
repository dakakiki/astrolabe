<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Services\ChartService;
use App\Http\Controllers\Concerns\ShowsNatalChart;
use App\Http\Controllers\Controller;
use App\Models\RelatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RelatedPersonChartController extends Controller
{
    use ShowsNatalChart;

    /** The related person's natal chart, calculated and cached as a client's; see ShowsNatalChart. */
    public function show(Request $request, RelatedPerson $relatedPerson, ChartService $charts): JsonResponse
    {
        Gate::authorize('view', $relatedPerson);

        return $this->natalChart($request, $relatedPerson, $charts);
    }
}
