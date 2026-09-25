<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tasks\SaveTask;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The practice's tasks and follow-ups (docs/spec/02). "Today" and "overdue"
 * are measured on the viewer's own calendar.
 */
class TaskController extends Controller
{
    public const DUE_FILTERS = ['overdue', 'today', 'upcoming', 'none'];

    private const RELATIONS = ['client', 'consultation.service', 'assignedUser', 'creator'];

    /**
     * Open tasks by deadline (none last), done ones most recent first. The
     * response also carries `counts` for the tabs: open, overdue, today, done —
     * within the same client, consultation or assignee.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'done', 'all'])],
            'due' => ['nullable', Rule::in(self::DUE_FILTERS)],
            'client_id' => ['nullable', 'integer'],
            'consultation_id' => ['nullable', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$now, $tomorrow] = $this->day($request);
        $status = isset($filters['due']) ? 'open' : ($filters['status'] ?? 'open');

        $scope = fn (): Builder => Task::query()
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['consultation_id'] ?? null, fn (Builder $query, int $id) => $query->where('consultation_id', $id))
            ->when($filters['assigned_user_id'] ?? null, fn (Builder $query, int $id) => $query->where('assigned_user_id', $id));

        $tasks = $scope()
            ->with(self::RELATIONS)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['due'] ?? null, fn (Builder $query, string $due) => match ($due) {
                'overdue' => $query->overdue($now),
                'today' => $query->dueToday($now, $tomorrow),
                'upcoming' => $query->dueLater($tomorrow),
                'none' => $query->whereNull('due_at'),
            })
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                'title', 'like', '%'.addcslashes($search, '%_\\').'%',
            ))
            ->tap(fn (Builder $query) => match ($status) {
                'done' => $query->orderByDesc('completed_at')->orderByDesc('id'),
                'all' => $query->orderByRaw('status = ?', [TaskStatus::Done->value])->byDeadline(),
                default => $query->byDeadline(),
            })
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return TaskResource::collection($tasks)->additional(['counts' => [
            'open' => $scope()->open()->count(),
            'overdue' => $scope()->overdue($now)->count(),
            'today' => $scope()->dueToday($now, $tomorrow)->count(),
            'done' => $scope()->where('status', TaskStatus::Done->value)->count(),
        ]]);
    }

    public function store(SaveTaskRequest $request, SaveTask $save): TaskResource
    {
        return TaskResource::make($save->handle(new Task, $request->validated()));
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return TaskResource::make($task->load(self::RELATIONS));
    }

    public function update(SaveTaskRequest $request, Task $task, SaveTask $save): TaskResource
    {
        return TaskResource::make($save->handle($task, $request->validated()));
    }

    /** A soft delete; the task leaves the lists and the client's timeline. */
    public function destroy(Task $task): Response
    {
        Gate::authorize('delete', $task);

        $task->delete();

        return response()->noContent();
    }

    /**
     * Now, and the start of tomorrow on the viewer's calendar.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function day(Request $request): array
    {
        $now = CarbonImmutable::now();

        return [$now, $now->setTimezone($request->user()->timezone ?: 'UTC')->startOfDay()->addDay()];
    }
}
