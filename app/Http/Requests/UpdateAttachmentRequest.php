<?php

namespace App\Http\Requests;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Rename a file or link, or change who may see it. The file itself never changes.
 */
class UpdateAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Throws with the policy's own status: someone else's private file is a 404.
        Gate::authorize('update', $this->route('attachment'));

        return true;
    }

    public function rules(): array
    {
        return [
            'original_name' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'required', Rule::enum(Visibility::class)],
        ];
    }
}
