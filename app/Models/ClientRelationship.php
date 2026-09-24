<?php

namespace App\Models;

use App\Enums\RelationshipType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link from a client to another client or to a related person — exactly one
 * of the two. `relationship_type` says what the other party is to the client;
 * seen from a linked client's own profile, the type is inverted
 * (RelationshipType::inverse()).
 *
 * @property RelationshipType $relationship_type
 */
#[Fillable(['relationship_type', 'notes'])]
class ClientRelationship extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'relationship_type' => RelationshipType::class,
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function relatedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'related_client_id');
    }

    /**
     * @return BelongsTo<RelatedPerson, $this>
     */
    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(RelatedPerson::class);
    }

    /** The type as seen from the given client's profile. */
    public function typeFor(Client $viewer): RelationshipType
    {
        return $this->related_client_id === $viewer->id ? $this->relationship_type->inverse() : $this->relationship_type;
    }
}
