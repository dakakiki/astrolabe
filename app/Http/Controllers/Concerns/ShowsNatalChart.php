<?php

namespace App\Http\Controllers\Concerns;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Services\ChartService;
use App\Enums\HouseSystem;
use App\Http\Resources\ChartResource;
use App\Models\Client;
use App\Models\RelatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

trait ShowsNatalChart
{
    /**
     * The natal chart, calculated on first request and cached after. Incomplete
     * birth data is a normal state, not an error: the response says what is
     * missing. An engine failure leaves the rest of the app working.
     *
     * `?house_system=` draws the chart in another system than the workspace's
     * default, without changing the default; each system is cached on its own.
     */
    protected function natalChart(Request $request, Client|RelatedPerson $subject, ChartService $charts): JsonResponse
    {
        $houseSystem = $request->validate([
            'house_system' => ['nullable', Rule::enum(HouseSystem::class)],
        ])['house_system'] ?? null;

        try {
            // 200 even when just calculated: reading a chart is not creating one.
            return ChartResource::make($charts->natal(
                $subject->loadMissing(['birthDetails', 'workspace']),
                $houseSystem ? HouseSystem::from($houseSystem) : null,
            ))
                ->response()
                ->setStatusCode(200);
        } catch (IncompleteBirthData $incomplete) {
            return response()->json(['data' => ['status' => 'incomplete', 'missing' => $incomplete->missing]]);
        } catch (EphemerisException $failure) {
            Log::error('Chart calculation failed', [
                'subject' => $subject->getMorphClass(),
                'id' => $subject->getKey(),
                'error' => $failure->getMessage(),
            ]);

            return response()->json(['message' => __('charts.unavailable')], 503);
        }
    }
}
