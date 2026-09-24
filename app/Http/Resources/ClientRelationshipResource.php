<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\ClientRelationship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One link on a client's "Related people" tab, seen from that client: the
 * other party — a related person or another client — and what they are to
 * the client. A link another client made shows the type the other way round.
 *
 * @mixin ClientRelationship
 */
class ClientRelationshipResource extends JsonResource
{
    public function __construct(ClientRelationship $relationship, private readonly Client $viewer)
    {
        parent::__construct($relationship);
    }

    public function toArray(Request $request): array
    {
        $incoming = $this->related_client_id === $this->viewer->id;
        $other = $incoming ? $this->client : $this->relatedClient;
        $person = $incoming ? null : $this->relatedPerson;
        $party = $person ?? $other;

        return [
            'id' => $this->id,
            'relationship_type' => $this->typeFor($this->viewer)->value,
            'notes' => $this->notes,
            // "incoming": another client made the link; it is still edited and removed from here.
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'kind' => $person ? 'person' : 'client',
            'party' => [
                'id' => $party->id,
                'full_name' => $party->fullName(),
                'status' => $person ? null : $other->status->value,
                'birth' => $party->birthDetails ? [
                    'birth_date' => $party->birthDetails->birth_date?->format('Y-m-d'),
                    'birth_time' => $party->birthDetails->birth_time ? substr($party->birthDetails->birth_time, 0, 5) : null,
                    'time_accuracy' => $party->birthDetails->time_accuracy->value,
                    'birth_place' => $party->birthDetails->birth_place,
                    'birth_country_code' => $party->birthDetails->birth_country_code,
                    'chart_ready' => $party->birthDetails->missingForChart() === [],
                ] : null,
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
