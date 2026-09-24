<?php

namespace App\Actions\RelatedPeople;

use App\Actions\Clients\SaveClient;
use App\Models\BirthDetails;
use App\Models\Client;
use App\Models\ClientRelationship;
use App\Models\RelatedPerson;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Makes a related person a client of their own without entering anything
 * again (docs/spec/02, "Povezane osobe"):
 *
 * - personal data and birth data are copied as they are — the frozen place is
 *   not looked up again, so the chart comes out the same;
 * - every link to the person now points at the new client, so the clients
 *   they belonged with see the new client on their profiles;
 * - the person is soft-deleted and remembers which client they became.
 */
class ConvertRelatedPerson
{
    public function __construct(private readonly SaveClient $saveClient) {}

    public function handle(RelatedPerson $person): Client
    {
        return DB::transaction(function () use ($person) {
            $client = $this->saveClient->handle(new Client, $person->only(['first_name', 'last_name', 'email', 'phone']));

            if ($birth = $person->birthDetails) {
                // The stored values themselves, not re-parsed or re-resolved.
                $client->birthDetails()->create(Arr::only($birth->getAttributes(), BirthDetails::FIELDS));
            }

            ClientRelationship::query()
                ->where('related_person_id', $person->id)
                ->update(['related_client_id' => $client->id, 'related_person_id' => null]);

            $person->converted_client_id = $client->id;
            $person->save();
            $person->delete();

            return $client->load(['tags', 'astrologyMethods', 'birthDetails']);
        });
    }
}
