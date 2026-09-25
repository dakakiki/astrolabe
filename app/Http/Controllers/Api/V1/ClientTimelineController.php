<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityEventResource;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The client's timeline (docs/spec/02), newest first, from the activity_events
 * projection: one indexed query, paginated, whatever the mix of entries.
 */
class ClientTimelineController extends Controller
{
    public const FILTERS = ['all', 'appointments', 'consultations', 'notes', 'files', 'tasks', 'charts', 'profile'];

    public function index(Request $request, Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(self::FILTERS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $type = $filters['type'] ?? 'all';

        $events = $client->activityEvents()
            ->visibleTo($request->user())
            ->with('creator')
            ->when($type !== 'all', fn (Builder $query) => $query->whereIn(
                'event_type',
                array_map(fn (ActivityType $event) => $event->value, ActivityType::inCategory($type)),
            ))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return ActivityEventResource::collection($events);
    }
}
