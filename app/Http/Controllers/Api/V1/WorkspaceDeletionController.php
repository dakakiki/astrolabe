<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Notifications\PracticeDeletionCancelled;
use App\Notifications\PracticeDeletionScheduled;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deleting the practice and the accounts that belong only to it (Settings →
 * Your data; docs/spec/06). The owner schedules it with their password; for
 * `practice_deletion_days` the practice is closed and the deletion can be
 * cancelled, then `data:prune` deletes everything (DeletePractice). Every
 * member is told by email both times.
 */
class WorkspaceDeletionController extends Controller
{
    public function store(Request $request, CurrentWorkspace $current): WorkspaceResource
    {
        $workspace = $current->get();
        Gate::authorize('delete', $workspace);

        $request->validate(['password' => ['required', 'string', 'current_password']]);

        abort_if($workspace->isPendingDeletion(), Response::HTTP_CONFLICT, __('workspaces.already_pending_deletion'));

        $days = config('astrolabe.retention.practice_deletion_days');
        $now = CarbonImmutable::now();
        $workspace->forceFill([
            'deletion_requested_at' => $now,
            'deletion_requested_by' => $request->user()->getKey(),
            'deletes_at' => $now->addDays($days),
        ])->save();

        Audit::record(AuditEvent::PracticeDeletionRequested, $workspace, ['days' => $days]);

        Notification::send(
            $workspace->users()->wherePivot('status', MembershipStatus::Active->value)->get(),
            new PracticeDeletionScheduled($workspace->name, $workspace->deletes_at->toIso8601ZuluString()),
        );

        return WorkspaceResource::make($workspace);
    }

    public function destroy(CurrentWorkspace $current): WorkspaceResource
    {
        $workspace = $current->get();
        Gate::authorize('delete', $workspace);

        abort_unless($workspace->isPendingDeletion(), Response::HTTP_CONFLICT, __('workspaces.not_pending_deletion'));

        $workspace->forceFill([
            'deletion_requested_at' => null,
            'deletion_requested_by' => null,
            'deletes_at' => null,
        ])->save();

        Audit::record(AuditEvent::PracticeDeletionCancelled, $workspace);

        Notification::send(
            $workspace->users()->wherePivot('status', MembershipStatus::Active->value)->get(),
            new PracticeDeletionCancelled($workspace->name),
        );

        return WorkspaceResource::make($workspace);
    }
}
