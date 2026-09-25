<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Services\SkyCalendar;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SkyController extends Controller
{
    /**
     * The sky calendar (docs/spec/02, "Nebo"; Phase 7d): `days` days from the
     * day `from` (default today), whole days on the user's clock, in the
     * workspace's zodiac. An engine failure answers as for charts.
     */
    public function __invoke(Request $request, SkyCalendar $sky, CurrentWorkspace $current): JsonResponse
    {
        $input = $request->validate([
            // The search for the other passes of an aspect reaches a year further; the ephemeris covers 1800–2400.
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1802-01-01', 'before:2398-01-01'],
            'days' => ['nullable', 'integer', Rule::in(SkyCalendar::PERIODS)],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
        ]);

        $zone = $input['timezone'] ?? $request->user()->timezone ?? 'UTC';
        $days = (int) ($input['days'] ?? SkyCalendar::DEFAULT_PERIOD);
        $start = isset($input['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], $zone)
            : CarbonImmutable::now($zone)->startOfDay();

        try {
            $calendar = $sky->calendar($current->get(), $start, $start->addDays($days), CarbonImmutable::now('UTC'));
        } catch (EphemerisException $failure) {
            Log::error('Sky calendar failed', ['error' => $failure->getMessage()]);

            return response()->json(['message' => __('charts.unavailable')], 503);
        }

        return response()->json(['data' => [
            'from' => $start->format('Y-m-d'),
            'days' => $days,
            'timezone' => $zone,
            ...$calendar,
        ]]);
    }
}
