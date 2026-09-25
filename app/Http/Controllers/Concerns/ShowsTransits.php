<?php

namespace App\Http\Controllers\Concerns;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Services\TransitService;
use App\Http\Resources\ChartResource;
use App\Models\Client;
use App\Models\RelatedPerson;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait ShowsTransits
{
    /**
     * Transits to the natal chart at a moment (docs/spec/11, Phase 7a):
     * `at` is wall-clock time ("2026-10-05T15:00") in `timezone` (default the
     * user's zone); without it, now. The natal chart comes along, so the
     * transits can be drawn around it. Incomplete birth data and an engine
     * failure answer as for the natal chart.
     */
    protected function transitsFor(Request $request, Client|RelatedPerson $subject, TransitService $transits): JsonResponse
    {
        $input = $request->validate([
            // The search for exact dates reaches a year either side; the ephemeris covers 1800–2400.
            'at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:1801-01-01', 'before:2399-01-01'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
        ]);

        $zone = $input['timezone'] ?? $request->user()->timezone ?? 'UTC';
        $moment = isset($input['at'])
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['at'], $zone)->utc()
            : CarbonImmutable::now('UTC')->startOfMinute();

        try {
            $report = $transits->transits($subject->loadMissing(['birthDetails', 'workspace']), $moment);
        } catch (IncompleteBirthData $incomplete) {
            return response()->json(['data' => ['status' => 'incomplete', 'missing' => $incomplete->missing]]);
        } catch (EphemerisException $failure) {
            Log::error('Transit calculation failed', [
                'subject' => $subject->getMorphClass(),
                'id' => $subject->getKey(),
                'error' => $failure->getMessage(),
            ]);

            return response()->json(['message' => __('charts.unavailable')], 503);
        }

        $report['natal'] = ChartResource::make($report['natal'])->resolve($request);

        return response()->json(['data' => ['status' => 'ready', 'timezone' => $zone] + $report]);
    }
}
