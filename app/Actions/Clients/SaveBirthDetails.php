<?php

namespace App\Actions\Clients;

use App\Astrology\Contracts\Geocoder;
use App\Enums\ActivityType;
use App\Enums\GeocodeSource;
use App\Enums\TimeAccuracy;
use App\Models\BirthDetails;
use App\Models\Client;
use App\Models\RelatedPerson;
use App\Support\Activity\ActivityLog;
use Illuminate\Validation\ValidationException;

/**
 * Stores the birth data of a client or a related person as entered and freezes
 * the location (docs/spec/02):
 *
 * - a place picked from the gazetteer is copied in once — name, country,
 *   coordinates and time zone — and never looked up again while it stays chosen;
 * - hand-entered coordinates and zone are kept as typed and marked "manual";
 * - no location at all is allowed, the chart then reports what is missing.
 *
 * Only a client's changes go on a timeline.
 */
class SaveBirthDetails
{
    /** Stored columns grouped into what the astrologer thinks of as one thing. */
    private const FIELD_GROUPS = [
        'birth_date' => 'date',
        'birth_time' => 'time',
        'time_accuracy' => 'time_accuracy',
        'birth_place' => 'place',
        'birth_country_code' => 'place',
        'latitude' => 'place',
        'longitude' => 'place',
        'place_id' => 'place',
        'geocode_source' => 'place',
        'birth_timezone' => 'timezone',
        'data_source' => 'data_source',
        'notes' => 'notes',
    ];

    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly ActivityLog $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated "birth" payload
     */
    public function handle(Client|RelatedPerson $owner, array $input): BirthDetails
    {
        $details = $owner->birthDetails ?? $owner->birthDetails()->make();
        $adding = ! $details->exists;

        $accuracy = TimeAccuracy::from($input['time_accuracy']);

        $details->fill([
            'birth_date' => $input['birth_date'] ?? null,
            'birth_time' => $accuracy->hasTime() ? ($input['birth_time'] ?? null) : null,
            'time_accuracy' => $accuracy,
            'data_source' => $input['data_source'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);

        $this->applyLocation($details, $input);
        $changed = $this->changedFields($details);

        $details->save();
        $owner->setRelation('birthDetails', $details);

        // A client created with birth data already has its "created" entry.
        if ($owner instanceof Client && ! $owner->wasRecentlyCreated && ($adding || $changed !== [])) {
            $this->activity->record($owner, ActivityType::BirthDetailsUpdated, [
                'added' => $adding,
                'fields' => $adding ? [] : $changed,
            ]);
        }

        return $details;
    }

    /**
     * @return list<string>
     */
    private function changedFields(BirthDetails $details): array
    {
        $dirty = $details->getDirty();

        // "14:30" typed again over the stored "14:30:00" is no change.
        if (array_key_exists('birth_time', $dirty)
            && substr((string) $details->getOriginal('birth_time'), 0, 5) === substr((string) $dirty['birth_time'], 0, 5)) {
            unset($dirty['birth_time']);
        }

        return array_values(array_unique(array_intersect_key(self::FIELD_GROUPS, $dirty)));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyLocation(BirthDetails $details, array $input): void
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
