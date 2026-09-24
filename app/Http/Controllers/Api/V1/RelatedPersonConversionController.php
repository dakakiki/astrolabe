<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RelatedPeople\ConvertRelatedPerson;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\RelatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RelatedPersonConversionController extends Controller
{
    /** Makes the person a client of their own; see ConvertRelatedPerson. */
    public function store(RelatedPerson $relatedPerson, ConvertRelatedPerson $convert): JsonResponse
    {
        Gate::authorize('update', $relatedPerson);
        Gate::authorize('create', Client::class);

        return ClientResource::make($convert->handle($relatedPerson->load('birthDetails')))
            ->withNotes()
            ->response()
            ->setStatusCode(201);
    }
}
