<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The signed-in user and the workspace they act in. Available before the
     * email address is verified, so the SPA can show the verification notice.
     * The operator's admin has no workspace (null) and `is_admin: true`.
     */
    public function __invoke(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $workspace = $current->get();

        return response()->json([
            'data' => [
                'user' => UserResource::make($request->user())->resolve($request),
                'workspace' => $workspace ? WorkspaceResource::make($workspace)->resolve($request) : null,
            ],
        ]);
    }
}
