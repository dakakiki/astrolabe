<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Contracts\Geocoder;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Birth-place lookup for the client form. Place data: GeoNames, CC BY 4.0.
 */
class PlaceController extends Controller
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /** Autocomplete; places in the practice's own country rank first. */
    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return PlaceResource::collection(
            $this->geocoder->search($input['q'], $input['limit'] ?? 8, $current->get()->countryCode())
        );
    }

    /** The closest known place, to suggest a time zone for hand-entered coordinates. */
    public function nearest(Request $request): JsonResponse
    {
        $input = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $place = $this->geocoder->nearest((float) $input['latitude'], (float) $input['longitude']);

        return response()->json(['data' => $place ? PlaceResource::make($place)->resolve($request) : null]);
    }
}
