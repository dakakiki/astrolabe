<?php

namespace App\Actions\RelatedPeople;

use App\Actions\Clients\SaveBirthDetails;
use App\Models\Client;
use App\Models\RelatedPerson;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates a related person together with the link to their client, or updates
 * one; birth data is stored and frozen exactly as for clients. Only the keys
 * present in the input are changed.
 */
class SaveRelatedPerson
{
    private const FIELDS = ['first_name', 'last_name', 'email', 'phone'];

    public function __construct(private readonly SaveBirthDetails $saveBirthDetails) {}

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(RelatedPerson $person, array $input): RelatedPerson
    {
        return DB::transaction(function () use ($person, $input) {
            $creating = ! $person->exists;
            $person->fill(Arr::only($input, self::FIELDS))->save();

            if ($creating) {
                $client = Client::query()->findOrFail($input['client_id']);

                $client->relationships()->make([
                    'relationship_type' => $input['relationship_type'],
                    'notes' => $input['notes'] ?? null,
                ])->relatedPerson()->associate($person)->save();

                $client->touchActivity();
            }

            if (! empty($input['birth'])) {
                $this->saveBirthDetails->handle($person, $input['birth']);
            }

            return $person->load(['birthDetails', 'relationships.client']);
        });
    }
}
