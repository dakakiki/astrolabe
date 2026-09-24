<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * The current workspace. There is deliberately no {workspace} route parameter:
 * a request can only ever address the workspace it acts in.
 */
class WorkspaceController extends Controller
{
    public function show(CurrentWorkspace $current): WorkspaceResource
    {
        return WorkspaceResource::make($current->get());
    }

    public function update(UpdateWorkspaceRequest $request, CurrentWorkspace $current): WorkspaceResource
    {
        $workspace = $current->get();
        $workspace->update($request->workspaceAttributes());

        return WorkspaceResource::make($workspace);
    }
}
