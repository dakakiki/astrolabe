<?php

namespace App\Http\Requests;

use App\Enums\MembershipStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Consultation;
use App\Models\Task;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or change (PATCH, only the fields sent) a task.
 *
 * The due date is a day (`due_date`, "2026-10-05"), optionally with a time
 * (`due_time`, "15:00"), in `timezone` (default: the user's zone). A follow-up
 * names its consultation; the client then comes from it. `status` (open/done)
 * changes only on an existing task.
 */
class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            ? $this->user()->can('update', $task)
            : $this->user()->can('create', Task::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $creating = ! $this->route('task') instanceof Task;

        return [
            'title' => [...($creating ? [] : ['sometimes']), 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'consultation_id' => [
                'nullable',
                'integer',
                Rule::exists('consultations', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'priority' => [...($creating ? ['nullable'] : ['sometimes', 'required']), Rule::enum(TaskPriority::class)],
            'status' => $creating ? ['prohibited'] : ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', MembershipStatus::Active->value),
            ],
        ];
    }

    /**
     * A follow-up's consultation belongs to the task's client, and a time needs a day.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $task = $this->route('task');
            $task = $task instanceof Task ? $task : null;
            $errors = $validator->errors();

            $clientId = $this->has('client_id') ? $this->input('client_id') : $task?->client_id;
            $consultationId = $this->has('consultation_id') ? $this->input('consultation_id') : $task?->consultation_id;

            if ($consultationId !== null && ! $errors->hasAny(['client_id', 'consultation_id'])) {
                $owner = Consultation::query()->whereKey($consultationId)->value('client_id');
                // A new follow-up may leave the client out; it is the consultation's.
                $derived = $task === null && ! $this->filled('client_id');

                if (! $derived && (int) $owner !== (int) $clientId) {
                    $errors->add('consultation_id', __('tasks.consultation_other_client'));
                }
            }

            // Removing the date removes its time too; a time sent without any date is an error.
            $dueDate = $this->has('due_date') ? $this->input('due_date') : $task?->due_date;

            if ($this->filled('due_time') && $dueDate === null && ! $errors->has('due_date')) {
                $errors->add('due_date', __('tasks.time_without_date'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => __('tasks.status_on_create'),
        ];
    }
}
