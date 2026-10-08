<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin's Feedback inbox (Phase 8c): what astrologers sent with the
 * "Feedback" button, open ones first; the operator marks them handled.
 */
class AdminFeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'handled', 'all'])],
        ]);

        $status = $filters['status'] ?? 'open';

        $feedback = Feedback::query()
            ->with(['user', 'workspace'])
            ->when($status === 'open', fn (Builder $query) => $query->whereNull('handled_at'))
            ->when($status === 'handled', fn (Builder $query) => $query->whereNotNull('handled_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        Audit::record(AuditEvent::AdminViewed, properties: ['screen' => 'feedback', 'filters' => $status === 'open' ? null : ['status']]);

        return response()->json([
            'data' => collect($feedback->items())->map(fn (Feedback $item) => $this->row($item)),
            'meta' => [
                'current_page' => $feedback->currentPage(),
                'from' => $feedback->firstItem(),
                'to' => $feedback->lastItem(),
                'last_page' => $feedback->lastPage(),
                'total' => $feedback->total(),
                'per_page' => $feedback->perPage(),
                'open' => Feedback::query()->whereNull('handled_at')->count(),
            ],
        ]);
    }

    public function update(Request $request, Feedback $feedback): JsonResponse
    {
        $data = $request->validate(['handled' => ['required', 'boolean']]);

        $feedback->forceFill(['handled_at' => $data['handled'] ? now() : null])->save();

        return response()->json(['data' => $this->row($feedback->load(['user', 'workspace']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Feedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'category' => $feedback->category->value,
            'message' => $feedback->message,
            'page' => $feedback->page,
            'user_agent' => $feedback->user_agent,
            'app_version' => $feedback->app_version,
            'created_at' => $feedback->created_at?->toIso8601ZuluString(),
            'handled_at' => $feedback->handled_at?->toIso8601ZuluString(),
            'user' => $feedback->user ? ['id' => $feedback->user->id, 'name' => $feedback->user->name, 'email' => $feedback->user->email] : null,
            'practice' => $feedback->workspace ? ['id' => $feedback->workspace->id, 'name' => $feedback->workspace->name] : null,
        ];
    }
}
