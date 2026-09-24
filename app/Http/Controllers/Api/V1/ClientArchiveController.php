<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Support\Facades\Gate;

/**
 * Archiving hides a client from the default list without losing any history;
 * restoring makes them active again.
 */
class ClientArchiveController extends Controller
{
    public function store(Client $client): ClientResource
    {
        return $this->setStatus($client, ClientStatus::Archived);
    }

    public function destroy(Client $client): ClientResource
    {
        return $this->setStatus($client, ClientStatus::Active);
    }

    private function setStatus(Client $client, ClientStatus $status): ClientResource
    {
        Gate::authorize('update', $client);

        $client->update(['status' => $status]);

        return ClientResource::make($client->load(['tags', 'astrologyMethods', 'birthDetails']))->withNotes();
    }
}
