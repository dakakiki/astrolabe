<?php

namespace App\Http\Requests;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Support\RichText;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or update (PATCH, only the fields sent) a consultation.
 *
 * `starts_at` is the local wall-clock time ("2026-09-24T15:00") in `timezone`,
 * which defaults to the astrologer's own zone; it is stored in UTC. The client
 * is chosen once, on creation.
 */
class SaveConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $consultation = $this->route('consultation');

        return $consultation instanceof Consultation
            ? $this->user()->can('update', $consultation)
            : $this->user()->can('create', Consultation::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $creating = ! $this->route('consultation') instanceof Consultation;
        $sometimes = $creating ? [] : ['sometimes'];
        $richText = ['nullable', 'string', 'max:'.RichText::MAX_LENGTH];

        return [
            'client_id' => $creating
                ? ['required', 'integer', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')]
                : ['prohibited'],
            'title' => ['nullable', 'string', 'max:150'],
            'status' => [...$sometimes, $creating ? 'nullable' : 'required', Rule::enum(ConsultationStatus::class)],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'topics' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => $richText,
            'client_summary' => $richText,
            'next_steps' => $richText,

            'method_ids' => ['sometimes', 'array', 'max:20'],
            'method_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('astrology_methods', 'id')->where(
                    fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId)
                ),
            ],
        ];
    }

    /**
     * Every status but a draft needs a date, also when a PATCH changes only one of the two.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['status', 'starts_at'])) {
                return;
            }

            $consultation = $this->route('consultation');

            $status = $this->has('status') && $this->input('status') !== null
                ? ConsultationStatus::from($this->input('status'))
                : ($consultation instanceof Consultation ? $consultation->status : ConsultationStatus::Draft);

            $hasDate = $this->has('starts_at')
                ? filled($this->input('starts_at'))
                : ($consultation instanceof Consultation && $consultation->starts_at !== null);

            if ($status->needsDate() && ! $hasDate) {
                $validator->errors()->add('starts_at', __('consultations.date_required'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'client_id.prohibited' => __('consultations.client_fixed'),
        ];
    }
}
