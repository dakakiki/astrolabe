<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Support\Audit\Audit;
use App\Support\Portal\PracticeLogo;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The practice's logo for the client portal (Settings → Branding, Phase 9a).
 * Every member sees it; the owner changes it.
 */
class WorkspaceLogoController extends Controller
{
    public function show(CurrentWorkspace $current): StreamedResponse
    {
        Gate::authorize('view', $current->get());

        return PracticeLogo::response($current->get());
    }

    public function store(Request $request, CurrentWorkspace $current): WorkspaceResource
    {
        $workspace = $current->get();
        Gate::authorize('update', $workspace);

        $request->validate([
            'logo' => ['required', 'file', 'max:'.config('portal.logo_max_kb')],
        ]);

        PracticeLogo::store($workspace, $request->file('logo'));
        Audit::record(AuditEvent::PracticeSettingsChanged, $workspace, ['fields' => ['logo']]);

        return WorkspaceResource::make($workspace);
    }

    public function destroy(CurrentWorkspace $current): WorkspaceResource
    {
        $workspace = $current->get();
        Gate::authorize('update', $workspace);

        if ($workspace->logo_path !== null) {
            PracticeLogo::remove($workspace);
            Audit::record(AuditEvent::PracticeSettingsChanged, $workspace, ['fields' => ['logo']]);
        }

        return WorkspaceResource::make($workspace);
    }
}
