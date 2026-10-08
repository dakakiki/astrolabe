<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Support\Audit\Audit;
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

        if ($workspace->wasChanged()) {
            Audit::record(AuditEvent::PracticeSettingsChanged, $workspace, [
                'fields' => array_values(array_diff(array_keys($workspace->getChanges()), ['updated_at'])),
            ]);
        }

        return WorkspaceResource::make($workspace);
    }
}
