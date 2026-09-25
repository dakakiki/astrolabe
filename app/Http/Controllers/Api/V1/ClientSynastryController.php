<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Services\ChartService;
use App\Astrology\Services\SynastryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChartResource;
use App\Models\Client;
use App\Models\RelatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ClientSynastryController extends Controller
{
    /**
     * The client's natal chart compared with a related person's
     * (`?with_person=`) or another client's (`?with_client=`), with the
     * composite of the two (docs/spec/11, Phase 7e). Someone from another
     * workspace is a 404. Incomplete birth data on either side is a normal
     * state: the response says whose data is missing and what.
     */
    public function show(Request $request, Client $client, ChartService $charts, SynastryService $synastry): JsonResponse
    {
        Gate::authorize('view', $client);

        $input = $request->validate([
            'with_person' => ['required_without:with_client', 'prohibits:with_client', 'integer'],
            'with_client' => ['required_without:with_person', 'integer', Rule::notIn([$client->id])],
        ], [
            'with_client.not_in' => __('charts.compare_with_self'),
        ]);

        $other = isset($input['with_person'])
            ? RelatedPerson::query()->findOrFail($input['with_person'])
            : Client::query()->findOrFail($input['with_client']);

        Gate::authorize('view', $other);

        $people = [
            'client' => ['kind' => 'client', 'id' => $client->id, 'full_name' => $client->fullName()],
            'other' => [
                'kind' => $other instanceof RelatedPerson ? 'person' : 'client',
                'id' => $other->id,
                'full_name' => $other->fullName(),
            ],
        ];

        try {
            $natal = [];

            foreach (['client' => $client, 'other' => $other] as $side => $subject) {
                try {
                    $natal[$side] = $charts->natal($subject->loadMissing(['birthDetails', 'workspace']));
                } catch (IncompleteBirthData $incomplete) {
                    return response()->json(['data' => [
                        'status' => 'incomplete',
                        'side' => $side,
                        'missing' => $incomplete->missing,
                    ] + $people]);
                }
            }
        } catch (EphemerisException $failure) {
            Log::error('Synastry calculation failed', [
                'client' => $client->id,
                'other' => $other->getMorphClass().':'.$other->getKey(),
                'error' => $failure->getMessage(),
            ]);

            return response()->json(['message' => __('charts.unavailable')], 503);
        }

        $workspace = $client->workspace;
        $settings = $workspace->aspectSettings();
        $report = $synastry->compare($natal['client'], $natal['other'], $settings);

        $people['client']['chart'] = ChartResource::make($natal['client'])->resolve($request);
        $people['other']['chart'] = ChartResource::make($natal['other'])->resolve($request);

        return response()->json(['data' => ['status' => 'ready'] + $people + $report + [
            'orbs' => $settings->toArray(),
            'zodiac_mode' => $workspace->default_zodiac_mode->value,
            'ayanamsa' => $workspace->default_ayanamsa?->value,
        ]]);
    }
}
