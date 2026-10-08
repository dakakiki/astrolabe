<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogEntryResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The whole audit log for the operator (Phase 8c): every person, every
 * practice, newest first, with filters — and the failed sign-ins and lockouts
 * on their own. Reading it is itself recorded, with the filters' names.
 */
class AdminAuditLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'workspace_id' => ['nullable', 'integer'],
            'event' => ['nullable', Rule::enum(AuditEvent::class)],
            'email' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'warnings' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Days are on the operator's own calendar.
        $zone = $request->user()->timezone ?: 'UTC';

        $entries = AuditLog::query()
            ->with(['user', 'workspace'])
            // A person's own entries and what was done to their account (by the operator).
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $id) => $query->where(fn (Builder $query) => $query
                ->where('user_id', $id)
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'user')->where('subject_id', $id))))
            ->when($filters['workspace_id'] ?? null, fn (Builder $query, int $id) => $query->where('workspace_id', $id))
            ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($filters['email'] ?? null, fn (Builder $query, string $email) => $query->whereHas('user', fn (Builder $users) => $users
                ->where('email', 'like', '%'.addcslashes($email, '%_\\').'%')))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<', CarbonImmutable::parse($to, $zone)->addDay()->startOfDay()->utc()))
            ->when($request->boolean('warnings'), fn (Builder $query) => $query->warnings())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        $person = isset($filters['user_id']) ? User::query()->find($filters['user_id']) : null;

        Audit::record(AuditEvent::AdminViewed, $person, [
            'screen' => 'audit_log',
            'filters' => array_keys(array_filter($request->only(['user_id', 'workspace_id', 'event', 'email', 'from', 'to', 'warnings']), 'filled')) ?: null,
        ]);

        // For the filter and the page's wording (the admin has no practice reference data).
        return AuditLogEntryResource::collection($entries)->additional([
            'events' => array_column(AuditEvent::cases(), 'value'),
            'retention_months' => config('astrolabe.retention.audit_log_months'),
        ]);
    }
}
