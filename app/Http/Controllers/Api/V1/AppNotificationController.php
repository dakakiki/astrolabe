<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Appointment;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell in the header (docs/spec/09, "Obaveštenja"): the signed-in person's
 * notifications in the practice they have open and about their account, the
 * newest first, with how many are unread.
 *
 * A notice about an appointment carries the client's name, read now inside the
 * practice — a client deleted since leaves the notice without a name.
 */
class AppNotificationController extends Controller
{
    public const LIMIT = 30;

    public function index(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $user = $request->user();
        $workspaceId = $current->id();

        $items = AppNotification::query()
            ->visibleTo($user, $workspaceId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $ids = $items->pluck('data.appointment_id')->filter()->unique()->values();
        $clients = $ids->isEmpty() ? collect() : Appointment::query()
            ->with('client')
            ->whereKey($ids)
            ->get(['id', 'client_id', 'workspace_id'])
            ->mapWithKeys(fn (Appointment $appointment) => [$appointment->id => $appointment->client ? [
                'id' => $appointment->client->id,
                'full_name' => $appointment->client->fullName(),
            ] : null]);

        return response()->json([
            'data' => $items->map(fn (AppNotification $item) => [
                'id' => $item->id,
                'kind' => $item->kind,
                'data' => $item->data,
                'client' => isset($item->data['appointment_id']) ? ($clients[$item->data['appointment_id']] ?? null) : null,
                'read_at' => $item->read_at?->toIso8601ZuluString(),
                'created_at' => $item->created_at->toIso8601ZuluString(),
            ])->values(),
            'meta' => [
                'unread' => AppNotification::query()->visibleTo($user, $workspaceId)->whereNull('read_at')->count(),
            ],
        ]);
    }

    /** Seen: opened from the list. Someone else's is a 404. */
    public function read(Request $request, CurrentWorkspace $current, int $notification): JsonResponse
    {
        $item = AppNotification::query()->visibleTo($request->user(), $current->id())->findOrFail($notification);

        if ($item->read_at === null) {
            $item->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => ['id' => $item->id, 'read_at' => $item->read_at->toIso8601ZuluString()]]);
    }

    /** All of them seen, in the practice open now and about the account. */
    public function readAll(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $marked = AppNotification::query()
            ->visibleTo($request->user(), $current->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }
}
