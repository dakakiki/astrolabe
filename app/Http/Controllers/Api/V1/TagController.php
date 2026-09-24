<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    /** The workspace's tags with how many clients carry each, for filters and suggestions. */
    public function index(): JsonResponse
    {
        $tags = Tag::query()->withCount('clients')->orderBy('name')->get();

        return response()->json([
            'data' => $tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'clients_count' => $tag->clients_count,
            ]),
        ]);
    }
}
