<?php

namespace App\Http\Requests;

use App\Enums\RelationshipType;
use App\Models\Client;
use App\Models\ClientRelationship;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Links a client to another client (`related_client_id`) or to a related
 * person who is already linked elsewhere (`related_person_id`) — one of the
 * two. A new person is created with POST /related-people instead.
 */
class LinkRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $client = $this->route('client');

        return [
            'related_client_id' => [
                'nullable',
                'integer',
                'required_without:related_person_id',
                'prohibits:related_person_id',
                Rule::notIn([$client->id]),
                Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'related_person_id' => [
                'nullable',
                'integer',
                'required_without:related_client_id',
                Rule::exists('related_people', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'relationship_type' => ['required', Rule::enum(RelationshipType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * One link per pair: a client already linked from the other side counts too.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Client $client */
            $client = $this->route('client');
            $otherClient = $this->input('related_client_id');

            $exists = $otherClient !== null
                ? ClientRelationship::query()->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->where('client_id', $client->id)->where('related_client_id', $otherClient))
                    ->orWhere(fn (Builder $query) => $query->where('client_id', $otherClient)->where('related_client_id', $client->id)))
                    ->exists()
                : $client->relationships()->where('related_person_id', $this->input('related_person_id'))->exists();

            if ($exists) {
                $validator->errors()->add($otherClient !== null ? 'related_client_id' : 'related_person_id', __('clients.already_linked'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'related_client_id.not_in' => __('clients.cannot_link_self'),
        ];
    }
}
