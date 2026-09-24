<?php

namespace App\Actions\Clients;

use App\Astrology\Contracts\Geocoder;
use App\Enums\GeocodeSource;
use App\Enums\TimeAccuracy;
use App\Models\Client;
use App\Models\ClientBirthDetails;
use Illuminate\Validation\ValidationException;

/**
 * Stores birth data as entered and freezes the location (docs/spec/02):
 *
 * - a place picked from the gazetteer is copied in once — name, country,
 *   coordinates and time zone — and never looked up again while it stays chosen;
 * - hand-entered coordinates and zone are kept as typed and marked "manual";
 * - no location at all is allowed, the chart then reports what is missing.
 */
class SaveBirthDetails
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @param  array<string, mixed>  $input  validated "birth" payload
     */
    public function handle(Client $client, array $input): ClientBirthDetails
    {
        $details = $client->birthDetails ?? new ClientBirthDetails(['client_id' => $client->id]);
        $details->client_id = $client->id;

        $accuracy = TimeAccuracy::from($input['time_accuracy']);

        $details->fill([
            'birth_date' => $input['birth_date'] ?? null,
            'birth_time' => $accuracy->hasTime() ? ($input['birth_time'] ?? null) : null,
            'time_accuracy' => $accuracy,
            'data_source' => $input['data_source'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);

        $this->applyLocation($details, $input);

        $details->save();
        $client->setRelation('birthDetails', $details);

        return $details;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyLocation(ClientBirthDetails $details, array $input): void
    {
        $placeId = isset($input['place_id']) ? (string) $input['place_id'] : null;

        if ($placeId !== null) {
            $unchanged = $details->exists
                && $details->geocode_source === GeocodeSource::GeoNames
                && (string) $details->place_id === $placeId;

            // Still the same chosen place: keep the frozen copy, even if the
            // gazetteer has since been updated.
            if ($unchanged) {
                return;
            }

            $place = $this->geocoder->find($placeId)
                ?? throw ValidationException::withMessages(['birth.place_id' => __('clients.place_not_found')]);

            $details->fill([
                'birth_place' => $place->label,
                'birth_country_code' => $place->countryCode,
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
                'birth_timezone' => $place->timezone,
                'place_id' => (int) $place->id,
                'geocode_source' => $place->source,
            ]);

            return;
        }

        $manual = isset($input['latitude'], $input['longitude']);

        $details->fill([
            'birth_place' => $input['birth_place'] ?? null,
            'birth_country_code' => $input['birth_country_code'] ?? null,
            'latitude' => $manual ? round((float) $input['latitude'], 6) : null,
            'longitude' => $manual ? round((float) $input['longitude'], 6) : null,
            'birth_timezone' => $input['birth_timezone'] ?? null,
            'place_id' => null,
            'geocode_source' => $manual ? GeocodeSource::Manual : null,
        ]);
    }
}
