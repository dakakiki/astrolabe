<?php

namespace App\Http\Requests;

use App\Enums\Visibility;
use App\Models\Note;
use App\Support\RichText;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or update (PATCH) a note. A note belongs to one client, set on
 * creation; it may point at one of that client's consultations.
 */
class SaveNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        if ($note instanceof Note) {
            // Throws with the policy's own status: someone else's private note is a 404.
            Gate::authorize('update', $note);

            return true;
        }

        return $this->user()->can('create', Note::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $note = $this->route('note');
        $creating = ! $note instanceof Note;
        $sometimes = $creating ? [] : ['sometimes'];
        $clientId = $creating ? $this->integer('client_id') : $note->client_id;

        return [
            'client_id' => $creating
                ? ['required', 'integer', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')]
                : ['prohibited'],
            'consultation_id' => [
                'nullable',
                'integer',
                Rule::exists('consultations', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('client_id', $clientId)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['nullable', 'string', 'max:150'],
            'content' => [...$sometimes, 'required', 'string', 'max:'.RichText::MAX_LENGTH],
            'visibility' => [...$sometimes, $creating ? 'nullable' : 'required', Rule::enum(Visibility::class)],
        ];
    }

    /**
     * An editor that was emptied still sends markup ("<p></p>"); that is no content.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->has('content') && ! $validator->errors()->has('content')
                && RichText::sanitize($this->input('content')) === null) {
                $validator->errors()->add('content', __('validation.required', ['attribute' => 'content']));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'client_id.prohibited' => __('notes.client_fixed'),
        ];
    }
}
