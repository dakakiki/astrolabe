<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceExportResource;
use App\Jobs\BuildPracticeExport;
use App\Models\WorkspaceExport;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The practice export (Settings → Your data): the owner asks for one, a queued
 * job builds it (BuildPracticeExport), and the owner downloads it here — from
 * the app, or through the signed link in the "ready" email (routes/web.php),
 * which still needs them signed in. Available while the practice is closed for
 * deletion too: that is when it matters most.
 */
class WorkspaceExportController extends Controller
{
    public function index(CurrentWorkspace $current): AnonymousResourceCollection
    {
        Gate::authorize('export', $current->get());

        return WorkspaceExportResource::collection(
            WorkspaceExport::query()
                ->with('requester')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()
                ->latest('id')
                ->limit(10)
                ->get(),
        );
    }

    public function store(Request $request, CurrentWorkspace $current): WorkspaceExportResource
    {
        Gate::authorize('export', $current->get());

        abort_if(
            WorkspaceExport::query()->where('status', ExportStatus::Pending->value)->exists(),
            Response::HTTP_CONFLICT,
            __('workspaces.export_in_progress'),
        );

        $export = WorkspaceExport::query()->create([
            'status' => ExportStatus::Pending,
            'requested_by' => $request->user()->getKey(),
        ]);

        Audit::record(AuditEvent::PracticeExportRequested, $export);

        BuildPracticeExport::dispatch($export->getKey());

        return WorkspaceExportResource::make($export->refresh()->load('requester'));
    }

    public function download(CurrentWorkspace $current, WorkspaceExport $export): StreamedResponse
    {
        Gate::authorize('export', $current->get());
        abort_unless($export->isDownloadable(), Response::HTTP_NOT_FOUND, __('workspaces.export_not_ready'));

        Audit::record(AuditEvent::PracticeExportDownloaded, $export);

        return Storage::disk($export->disk)->download($export->path, $export->downloadName(), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }
}
