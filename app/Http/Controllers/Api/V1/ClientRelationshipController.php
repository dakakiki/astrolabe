<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RelationshipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\LinkRelationshipRequest;
use App\Http\Requests\UpdateRelationshipRequest;
use App\Http\Resources\ClientRelationshipResource;
use App\Models\Client;
use App\Models\ClientRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The "Related people" tab of a client: links to related people and to other
 * clients, including links other clients made to this one.
 */
class ClientRelationshipController extends Controller
{
    private const PARTIES = ['client.birthDetails', 'relatedClient.birthDetails', 'relatedPerson.birthDetails'];

    /** All links of the client — a short list, so not paginated — oldest first. */
    public function index(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $relationships = ClientRelationship::query()
            ->where(fn ($query) => $query->where('client_id', $client->id)->orWhere('related_client_id', $client->id))
            ->with(self::PARTIES)
            ->orderBy('id')
            ->get()
            // A party that is gone (soft-deleted) is not shown.
            ->filter(fn (ClientRelationship $relationship) => $relationship->related_client_id === $client->id
                ? $relationship->client !== null
                : ($relationship->relatedClient ?? $relationship->relatedPerson) !== null);

        return response()->json([
            'data' => $relationships
                ->map(fn (ClientRelationship $relationship) => (new ClientRelationshipResource($relationship, $client))->resolve($request))
                ->values(),
        ]);
    }

    /** Links the client to another client or to an existing related person. */
    public function store(LinkRelationshipRequest $request, Client $client): JsonResponse
    {
        $relationship = $client->relationships()->make($request->safe()->only(['relationship_type', 'notes']));
        $relationship->related_client_id = $request->validated('related_client_id');
        $relationship->related_person_id = $request->validated('related_person_id');
        $relationship->save();

        $client->touchActivity();

        return (new ClientRelationshipResource($relationship->load(self::PARTIES), $client))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRelationshipRequest $request, ClientRelationship $relationship): ClientRelationshipResource
    {
        $viewerId = (int) ($request->validated('as_seen_by') ?? $relationship->client_id);

        if ($request->has('relationship_type')) {
            $type = $request->enum('relationship_type', RelationshipType::class);
            // Chosen on the linked client's profile: stored the other way round.
            $relationship->relationship_type = $viewerId === $relationship->related_client_id ? $type->inverse() : $type;
        }

        if ($request->has('notes')) {
            $relationship->notes = $request->validated('notes');
        }

        $relationship->save();
        $relationship->load(self::PARTIES);

        $viewer = $viewerId === $relationship->related_client_id ? $relationship->relatedClient : $relationship->client;

        return new ClientRelationshipResource($relationship, $viewer);
    }

    /**
     * Removes the link. A related person left without any client goes with it
     * (soft delete): related people exist only through their clients.
     */
    public function destroy(ClientRelationship $relationship): Response
    {
        Gate::authorize('delete', $relationship);

        DB::transaction(function () use ($relationship) {
            $relationship->delete();

            $person = $relationship->relatedPerson;

            if ($person && ! $person->relationships()->exists()) {
                $person->delete();
            }
        });

        return response()->noContent();
    }
}
