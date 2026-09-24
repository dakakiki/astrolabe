<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RelatedPeople\SaveRelatedPerson;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRelatedPersonRequest;
use App\Http\Resources\RelatedPersonResource;
use App\Models\RelatedPerson;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Related people are reached through a client's "Related people" tab; there
 * is no list of them on their own.
 */
class RelatedPersonController extends Controller
{
    public function store(SaveRelatedPersonRequest $request, SaveRelatedPerson $save): RelatedPersonResource
    {
        return RelatedPersonResource::make($save->handle(new RelatedPerson, $request->validated()));
    }

    public function show(RelatedPerson $relatedPerson): RelatedPersonResource
    {
        Gate::authorize('view', $relatedPerson);

        return RelatedPersonResource::make($relatedPerson->load(['birthDetails', 'relationships.client']));
    }

    public function update(SaveRelatedPersonRequest $request, RelatedPerson $relatedPerson, SaveRelatedPerson $save): RelatedPersonResource
    {
        return RelatedPersonResource::make($save->handle($relatedPerson, $request->validated()));
    }

    /** Soft delete; the person's links to clients are removed with them. */
    public function destroy(RelatedPerson $relatedPerson): Response
    {
        Gate::authorize('delete', $relatedPerson);

        DB::transaction(function () use ($relatedPerson) {
            $relatedPerson->relationships()->delete();
            $relatedPerson->delete();
        });

        return response()->noContent();
    }
}
