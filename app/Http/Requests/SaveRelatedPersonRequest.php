<?php

namespace App\Http\Requests;

use App\Enums\RelationshipType;
use App\Http\Requests\Concerns\ValidatesClients;
use App\Models\Client;
use App\Models\RelatedPerson;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a related person linked to a client (POST: `client_id`,
 * `relationship_type`, `notes` for the link) or update one (PATCH, only the
 * fields sent). Birth data goes under `birth`, as for clients.
 */
class SaveRelatedPersonRequest extends FormRequest
{
    use ValidatesClients;

    public function authorize(): bool
    {
        $person = $this->route('relatedPerson');

        return $person instanceof RelatedPerson
            ? $this->user()->can('update', $person)
            : $this->user()->can('create', Client::class);
    }

    public function rules(): array
    {
        $creating = ! $this->route('relatedPerson') instanceof RelatedPerson;
        $sometimes = $creating ? [] : ['sometimes'];

        $rules = [
            'first_name' => [...$sometimes, 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];

        if ($creating) {
            $rules += [
                'client_id' => [
                    'required',
                    'integer',
                    Rule::exists('clients', 'id')
                        ->where('workspace_id', app(CurrentWorkspace::class)->id())
                        ->whereNull('deleted_at'),
                ],
                'relationship_type' => ['required', Rule::enum(RelationshipType::class)],
                'notes' => ['nullable', 'string', 'max:500'],
            ];
        }

        if ($this->filled('birth')) {
            $rules['birth'] = ['array'];
            $rules += $this->birthRules('birth.');
        }

        return $rules;
    }
}
