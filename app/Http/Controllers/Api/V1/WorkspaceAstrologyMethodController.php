<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncWorkspaceMethodsRequest;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Response;

class WorkspaceAstrologyMethodController extends Controller
{
    /**
     * Replace the workspace's method selection. At most one method is the default.
     */
    public function update(SyncWorkspaceMethodsRequest $request, CurrentWorkspace $current): Response
    {
        $defaultId = $request->integer('default_id') ?: null;

        $current->get()->astrologyMethods()->sync(
            collect($request->input('method_ids'))
                ->mapWithKeys(fn ($id) => [(int) $id => ['is_default' => (int) $id === $defaultId]])
                ->all()
        );

        return response()->noContent();
    }
}
