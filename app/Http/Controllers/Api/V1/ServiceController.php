<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Services\SaveService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    /**
     * All of the practice's services — a short list, so not paginated. Active
     * ones first. `status=active` is what forms offer for new records.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Service::class);

        $status = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ])['status'] ?? null;

        $services = Service::query()
            ->with('astrologyMethods')
            ->withExists(Service::usage())
            ->when($status, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return ServiceResource::collection($services);
    }

    public function store(SaveServiceRequest $request, SaveService $save): ServiceResource
    {
        return ServiceResource::make($save->handle(new Service, $request->validated()));
    }

    public function show(Service $service): ServiceResource
    {
        Gate::authorize('view', $service);

        return ServiceResource::make($service->load('astrologyMethods')->loadExists(Service::usage()));
    }

    public function update(SaveServiceRequest $request, Service $service, SaveService $save): ServiceResource
    {
        return ServiceResource::make($save->handle($service, $request->validated()));
    }

    /**
     * Only a service nothing refers to can be deleted; one in use is deactivated
     * instead, so past consultations keep it (409 says so).
     */
    public function destroy(Service $service): Response|JsonResponse
    {
        Gate::authorize('delete', $service);

        if ($service->isInUse()) {
            return response()->json(['message' => __('services.in_use')], 409);
        }

        $service->delete();

        return response()->noContent();
    }
}
