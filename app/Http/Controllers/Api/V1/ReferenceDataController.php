<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\ValueObjects\AspectSettings;
use App\Enums\AspectType;
use App\Enums\Ayanamsa;
use App\Enums\ClientStatus;
use App\Enums\ConsultationStatus;
use App\Enums\HouseSystem;
use App\Enums\LocationType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RelationshipType;
use App\Enums\ServiceColor;
use App\Enums\TimeAccuracy;
use App\Enums\Visibility;
use App\Enums\ZodiacMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveServiceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Country;
use App\Support\Attachments\AllowedFileTypes;
use App\Support\Attachments\UploadLimit;
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
                // Digits after the decimal point, for turning stored amounts into prices and back.
                'currency_decimals' => collect(config('astrolabe.currencies'))
                    ->mapWithKeys(fn (string $code) => [$code => config("astrolabe.currency_decimals.{$code}", 2)]),
                'house_systems' => array_column(HouseSystem::cases(), 'value'),
                'zodiac_modes' => array_column(ZodiacMode::cases(), 'value'),
                'ayanamsas' => array_column(Ayanamsa::cases(), 'value'),
                'aspects' => [
                    'types' => array_map(fn (AspectType $type) => [
                        'type' => $type->value,
                        'angle' => $type->angle(),
                        'major' => $type->isMajor(),
                    ], AspectType::cases()),
                    'defaults' => AspectSettings::defaults()->toArray(),
                    'transit_defaults' => AspectSettings::transitDefaults()->toArray(),
                    'max_orb' => UpdateWorkspaceRequest::MAX_ORB,
                    'max_luminary_bonus' => UpdateWorkspaceRequest::MAX_LUMINARY_BONUS,
                ],
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
                'consultation_statuses' => array_column(ConsultationStatus::cases(), 'value'),
                'visibilities' => array_column(Visibility::cases(), 'value'),
                'services' => [
                    'location_types' => array_column(LocationType::cases(), 'value'),
                    'colors' => array_column(ServiceColor::cases(), 'value'),
                    'max_duration' => SaveServiceRequest::MAX_DURATION,
                ],
                'relationship_types' => array_column(RelationshipType::cases(), 'value'),
                'payments' => [
                    'kinds' => array_column(PaymentKind::cases(), 'value'),
                    'methods' => array_column(PaymentMethod::cases(), 'value'),
                    'max_amount' => SaveServiceRequest::MAX_AMOUNT,
                ],
                'attachments' => [
                    'max_size' => UploadLimit::bytes(),
                    'extensions' => AllowedFileTypes::extensions(),
                ],
            ],
        ]);
    }
}
