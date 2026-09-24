<?php

namespace App\Http\Resources;

use App\Models\ClientRelationship;
use App\Models\RelatedPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A related person with their birth data and the clients they belong with.
 *
 * @mixin RelatedPerson
 */
class RelatedPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'birth' => $this->whenLoaded('birthDetails', fn () => $this->birthDetails
                ? BirthDetailsResource::make($this->birthDetails)->resolve()
                : null),
            // Links as seen from each client's profile: "partner of Ana Marković".
            'relationships' => $this->whenLoaded('relationships', fn () => $this->relationships->map(
                fn (ClientRelationship $relationship) => [
                    'id' => $relationship->id,
                    'relationship_type' => $relationship->relationship_type->value,
                    'notes' => $relationship->notes,
                    'client' => [
                        'id' => $relationship->client->id,
                        'full_name' => $relationship->client->fullName(),
                        'status' => $relationship->client->status->value,
                    ],
                ]
            )->values()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
