<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Consultations\SaveConsultation;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveConsultationRequest;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConsultationController extends Controller
{
    /**
     * Paginated list for the whole practice or one client, newest first. Dates in
     * `from` / `to` are calendar days in the astrologer's own time zone.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Consultation::class);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ConsultationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['-starts_at', 'starts_at'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $zone = $request->user()->timezone ?: 'UTC';

        $consultations = Consultation::query()
            ->with(['client', 'astrologyMethods'])
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->search($query, $search))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query
                ->where('starts_at', '>=', CarbonImmutable::parse($from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query
                ->where('starts_at', '<', CarbonImmutable::parse($to, $zone)->addDay()->startOfDay()->utc()))
            // Drafts without a date sit where they were created.
            ->orderBy(DB::raw('coalesce(starts_at, created_at)'), ($filters['sort'] ?? '-starts_at') === 'starts_at' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ConsultationResource::collection($consultations);
    }

    public function store(SaveConsultationRequest $request, SaveConsultation $save): ConsultationResource
    {
        return ConsultationResource::make($save->handle(new Consultation, $request->validated()))->withContent();
    }

    public function show(Consultation $consultation): ConsultationResource
    {
        Gate::authorize('view', $consultation);

        return ConsultationResource::make($consultation->load(['client', 'astrologyMethods', 'chart']))->withContent();
    }

    public function update(SaveConsultationRequest $request, Consultation $consultation, SaveConsultation $save): ConsultationResource
    {
        return ConsultationResource::make($save->handle($consultation, $request->validated()))->withContent();
    }

    /**
     * Soft delete, together with the files attached to it; notes that pointed at it
     * stay with the client.
     */
    public function destroy(Consultation $consultation): Response
    {
        Gate::authorize('delete', $consultation);

        DB::transaction(function () use ($consultation) {
            $consultation->attachments()->get()->each->delete();
            $consultation->delete();
        });

        return response()->noContent();
    }

    private function search(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('title', 'like', $like)
            ->orWhere('topics', 'like', $like)
            ->orWhereHas('client', fn (Builder $clients) => $clients
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])));
    }
}
