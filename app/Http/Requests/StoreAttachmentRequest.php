<?php

namespace App\Http\Requests;

use App\Enums\AttachmentKind;
use App\Enums\Visibility;
use App\Models\Attachment;
use App\Models\Consultation;
use App\Support\Attachments\AllowedFileTypes;
use App\Support\Attachments\UploadLimit;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A file upload (multipart) or a link, on a client or on one of their
 * consultations. The file's real type is read from its content.
 */
class StoreAttachmentRequest extends FormRequest
{
    /** @var array{mime: string, extension: string}|null */
    public ?array $detectedType = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', Attachment::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        return [
            'client_id' => [
                'required_without:consultation_id',
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'consultation_id' => [
                'nullable',
                'integer',
                Rule::exists('consultations', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'kind' => ['nullable', Rule::enum(AttachmentKind::class)],
            'file' => ['required_unless:kind,link', 'prohibited_if:kind,link', 'file', 'max:'.UploadLimit::kilobytes()],
            'url' => ['required_if:kind,link', 'prohibited_unless:kind,link', 'nullable', 'url:http,https', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // A consultation's files belong to its client; the two must agree when both are sent.
            if ($this->filled(['client_id', 'consultation_id'])
                && (int) Consultation::query()->whereKey($this->integer('consultation_id'))->value('client_id') !== $this->integer('client_id')) {
                $validator->errors()->add('consultation_id', __('attachments.consultation_mismatch'));
            }

            if ($this->hasFile('file')) {
                $this->detectedType = AllowedFileTypes::detect($this->file('file'));

                if ($this->detectedType === null) {
                    $validator->errors()->add('file', __('attachments.type_not_allowed', [
                        'types' => implode(', ', AllowedFileTypes::extensions()),
                    ]));
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'file.max' => __('attachments.too_large', ['size' => round(UploadLimit::bytes() / 1024 / 1024).' MB']),
        ];
    }
}
