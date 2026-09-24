<?php

namespace App\Http\Requests;

use App\Enums\RelationshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changes the type or the notes of a link. A link between two clients is
 * edited from either profile: `as_seen_by` names the client whose profile the
 * type was chosen on, and the type is stored the right way round.
 */
class UpdateRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('relationship'));
    }

    public function rules(): array
    {
        $relationship = $this->route('relationship');

        return [
            'relationship_type' => ['sometimes', 'required', Rule::enum(RelationshipType::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'as_seen_by' => [
                'nullable',
                'integer',
                Rule::in(array_filter([$relationship->client_id, $relationship->related_client_id])),
            ],
        ];
    }
}
