<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ayanamsa;
use App\Enums\ClientStatus;
use App\Enums\HouseSystem;
use App\Enums\TimeAccuracy;
use App\Enums\ZodiacMode;
use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;

/**
 * Option lists the SPA needs for its forms, so allowed values are defined once,
 * on the server. Labels are translated on the frontend by value.
 */
class ReferenceDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'locales' => collect(config('astrolabe.locales'))
                    ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
                    ->values(),
                'currencies' => config('astrolabe.currencies'),
                'house_systems' => array_column(HouseSystem::cases(), 'value'),
                'zodiac_modes' => array_column(ZodiacMode::cases(), 'value'),
                'ayanamsas' => array_column(Ayanamsa::cases(), 'value'),
                'countries' => Country::query()
                    ->orderBy('code')
                    ->get(['code', 'phone_code', 'currency_code', 'languages'])
                    ->map(fn (Country $country) => [
                        'code' => $country->code,
                        'phone_code' => $country->phone_code,
                        'currency_code' => $country->currency_code,
                        'languages' => $country->languages ? explode(',', $country->languages) : [],
                    ]),
                'client_statuses' => array_column(ClientStatus::cases(), 'value'),
                'time_accuracies' => array_column(TimeAccuracy::cases(), 'value'),
            ],
        ]);
    }
}
