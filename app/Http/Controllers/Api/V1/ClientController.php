<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\SaveClient;
use App\Enums\ClientStatus;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    /**
     * Paginated, searchable list. Archived clients are left out unless asked for.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_column(ClientStatus::cases(), 'value'), 'all'])],
            'tag' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', 'integer'],
            'activity' => ['nullable', Rule::in(['week', 'month', 'quarter', 'older'])],
            'sort' => ['nullable', Rule::in(['name', '-last_activity_at', '-created_at'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $clients = Client::query()
            ->with(['tags', 'astrologyMethods', 'birthDetails'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->search($query, $search))
            ->when(
                ($filters['status'] ?? null) === null,
                fn (Builder $query) => $query->where('status', '!=', ClientStatus::Archived->value),
                fn (Builder $query) => $filters['status'] === 'all' ? $query : $query->where('status', $filters['status']),
            )
            ->when($filters['tag'] ?? null, fn (Builder $query, string $tag) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('name', $tag)))
            ->when($filters['method'] ?? null, fn (Builder $query, int $method) => $query->whereHas('astrologyMethods', fn (Builder $methods) => $methods->whereKey($method)))
            ->when($filters['activity'] ?? null, fn (Builder $query, string $activity) => $this->activity($query, $activity))
            ->tap(fn (Builder $query) => $this->sort($query, $filters['sort'] ?? 'name'))
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ClientResource::collection($clients);
    }

    public function store(SaveClientRequest $request, SaveClient $saveClient): ClientResource
    {
        $client = $saveClient->handle(new Client, $request->validated());

        return ClientResource::make($client)->withNotes();
    }

    public function show(Request $request, Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return ClientResource::make($client->load(['tags', 'astrologyMethods', 'birthDetails']))
            ->withNotes()
            ->withStats([
                'consultations' => $client->consultations()->count(),
                'completed_consultations' => $client->consultations()->where('status', ConsultationStatus::Completed)->count(),
                'notes' => $client->notes()->visibleTo($request->user())->count(),
                'files' => $client->attachments()->visibleTo($request->user())->count(),
            ]);
    }

    public function update(SaveClientRequest $request, Client $client, SaveClient $saveClient): ClientResource
    {
        return ClientResource::make($saveClient->handle($client, $request->validated()))->withNotes();
    }

    private function search(Builder $query, string $search): void
    {
        // The collation compares without case and accents, so "sasa" finds "Saša".
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('first_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])
            ->orWhere('email', 'like', $like)
            ->orWhere('phone', 'like', $like));
    }

    private function activity(Builder $query, string $activity): void
    {
        match ($activity) {
            'week' => $query->where('last_activity_at', '>=', now()->subWeek()),
            'month' => $query->where('last_activity_at', '>=', now()->subMonth()),
            'quarter' => $query->where('last_activity_at', '>=', now()->subMonths(3)),
            'older' => $query->where(fn (Builder $query) => $query
                ->where('last_activity_at', '<', now()->subMonths(3))
                ->orWhereNull('last_activity_at')),
        };
    }

    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            '-last_activity_at' => $query->orderByDesc('last_activity_at'),
            '-created_at' => $query->orderByDesc('created_at'),
            default => $query->orderBy('last_name')->orderBy('first_name'),
        };

        $query->orderBy('id');
    }
}
