<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAstrologyMethodRequest;
use App\Http\Resources\AstrologyMethodResource;
use App\Models\AstrologyMethod;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AstrologyMethodController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Built-in methods followed by the workspace's own, each marked with whether
     * the workspace works with it. Short reference list, so not paginated.
     */
    public function index(): AnonymousResourceCollection
    {
        $methods = AstrologyMethod::query()
            ->orderByRaw('workspace_id is not null')
            ->orderBy('id')
            ->get();

        $collection = AstrologyMethodResource::collection($methods);
        $collection->collection->each->withSelection($this->selection());

        return $collection;
    }

    public function store(SaveAstrologyMethodRequest $request): AstrologyMethodResource
    {
        $method = new AstrologyMethod($request->methodAttributes());
        $method->workspace_id = $this->current->id();
        $method->save();

        return AstrologyMethodResource::make($method)->withSelection($this->selection());
    }

    public function update(SaveAstrologyMethodRequest $request, AstrologyMethod $astrologyMethod): AstrologyMethodResource
    {
        $astrologyMethod->update($request->methodAttributes());

        return AstrologyMethodResource::make($astrologyMethod)->withSelection($this->selection());
    }

    public function destroy(AstrologyMethod $astrologyMethod): Response
    {
        Gate::authorize('delete', $astrologyMethod);

        $astrologyMethod->delete();

        return response()->noContent();
    }

    /**
     * @return array<int, bool> method id => is_default
     */
    private function selection(): array
    {
        return $this->current->get()
            ->astrologyMethods()
            ->pluck('workspace_astrology_method.is_default', 'astrology_methods.id')
            ->map(fn ($isDefault) => (bool) $isDefault)
            ->all();
    }
}
