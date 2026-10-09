<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\RevokePortalAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientPortalResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A client's access to the practice's portal (docs/spec/12, "Pozivnica"): its
 * state, inviting (or inviting again), and revoking. Every member who works
 * with the client may do it; the client's history never changes.
 */
class ClientPortalController extends Controller
{
    public function show(Client $client): ClientPortalResource
    {
        Gate::authorize('view', $client);

        return ClientPortalResource::make($client);
    }

    public function invite(Request $request, Client $client, InviteToPortal $invite): ClientPortalResource
    {
        Gate::authorize('update', $client);

        $invite->handle($client, $request->user());

        return ClientPortalResource::make($client);
    }

    public function revoke(Request $request, Client $client, RevokePortalAccess $revoke): ClientPortalResource
    {
        Gate::authorize('update', $client);

        $revoke->handle($client, $request->user());

        return ClientPortalResource::make($client);
    }
}
