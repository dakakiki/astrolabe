<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AstrologerResource;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Support\Admin\Astrologers;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The admin's Astrologers screen (Phase 8c): every account but the admins',
 * with its practice's figures. Looking is recorded in the audit log too.
 */
class AdminAstrologerController extends Controller
{
    public const STATUSES = ['active', 'unverified', 'suspended', 'closing', 'no_two_factor'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Astrologers::query();
        Astrologers::filter($query, $filters['search'] ?? null, $filters['status'] ?? null);

        Audit::record(AuditEvent::AdminViewed, properties: [
            'screen' => 'astrologers',
            'filters' => array_keys(array_filter($request->only(['search', 'status']), 'filled')) ?: null,
        ]);

        return AstrologerResource::collection(
            $query->orderByDesc('users.created_at')->orderByDesc('users.id')
                ->paginate($filters['per_page'] ?? 50)
                ->withQueryString(),
        );
    }

    public function show(User $user): AstrologerResource
    {
        abort_if($user->isAdmin(), 404);

        $astrologer = Astrologers::query()->where('users.id', $user->getKey())->firstOrFail();
        $invitation = RegistrationInvitation::query()->where('user_id', $user->getKey())->latest('id')->first();

        Audit::record(AuditEvent::AdminViewed, $user, ['screen' => 'astrologer']);

        return AstrologerResource::make($astrologer)->additional(['meta' => [
            'invitation' => $invitation ? [
                'sent_at' => $invitation->created_at?->toIso8601ZuluString(),
                'accepted_at' => $invitation->accepted_at?->toIso8601ZuluString(),
                'note' => $invitation->note,
            ] : null,
            'memberships' => $user->workspaces()->get()->map(fn ($workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $workspace->membership->role->value,
                'status' => $workspace->membership->status->value,
            ]),
        ]]);
    }
}
