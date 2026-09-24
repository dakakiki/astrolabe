<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncWorkspaceMethodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', app(CurrentWorkspace::class)->get());
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        // Only built-in methods or this workspace's own; another workspace's id is
        // reported as invalid, exactly like an id that does not exist.
        $visible = Rule::exists('astrology_methods', 'id')->where(
            fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId)
        );

        return [
            'method_ids' => ['present', 'array', 'max:50'],
            'method_ids.*' => ['integer', 'distinct', $visible],
            'default_id' => ['nullable', 'integer', Rule::in($this->input('method_ids', []))],
        ];
    }

    public function messages(): array
    {
        return [
            'default_id.in' => __('validation.custom.default_method.in'),
        ];
    }
}
