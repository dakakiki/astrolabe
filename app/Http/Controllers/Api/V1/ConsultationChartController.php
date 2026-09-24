<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Services\ChartService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The chart snapshot on a consultation (docs/spec/02): the client's chart as
 * it stands when attached. Calculations are never overwritten, so later
 * corrections to birth data produce a new chart and leave this one as it was.
 */
class ConsultationChartController extends Controller
{
    public function store(Consultation $consultation, ChartService $charts): ConsultationResource|JsonResponse
    {
        Gate::authorize('update', $consultation);

        $client = $consultation->client()->with(['birthDetails', 'workspace'])->firstOrFail();

        try {
            $chart = $charts->natal($client);
        } catch (IncompleteBirthData $incomplete) {
            return response()->json([
                'message' => __('consultations.chart_incomplete'),
                'errors' => ['chart' => [__('consultations.chart_incomplete')]],
                'missing' => $incomplete->missing,
            ], 422);
        } catch (EphemerisException $failure) {
            Log::error('Chart calculation failed', ['client_id' => $client->id, 'error' => $failure->getMessage()]);

            return response()->json(['message' => __('charts.unavailable')], 503);
        }

        $consultation->chart()->associate($chart)->save();

        return ConsultationResource::make($consultation->load(['client', 'astrologyMethods', 'chart']))->withContent();
    }

    public function destroy(Consultation $consultation): ConsultationResource
    {
        Gate::authorize('update', $consultation);

        $consultation->chart()->dissociate()->save();

        return ConsultationResource::make($consultation->load(['client', 'astrologyMethods', 'chart']))->withContent();
    }
}
