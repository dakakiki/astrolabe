<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Booking\CancelPortalAppointment;
use App\Actions\Booking\RescheduleAppointment;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\BookAppointmentRequest;
use App\Http\Resources\Portal\PortalAppointmentResource;
use App\Models\Appointment;
use App\Models\PortalUser;
use App\Support\Portal\PortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Portal → Appointments (docs/spec/12): the client's own, upcoming (soonest
 * first) — booked or waiting for the practice to confirm — or past: held,
 * cancelled, declined, missed (newest first).
 *
 * Phase 9b: the client moves or cancels one here, until the practice's
 * deadline before it starts (RescheduleAppointment, CancelPortalAppointment).
 * Ids come from the path and are looked up among the client's own only, so
 * anyone else's appointment is not found.
 */
class AppointmentController extends Controller
{
    public function index(Request $request, PortalContext $context): AnonymousResourceCollection
    {
        $data = $request->validate(['when' => ['nullable', Rule::in(['upcoming', 'past'])]]);
        $upcoming = ($data['when'] ?? 'upcoming') === 'upcoming';

        $appointments = self::forClient($context)
            ->with('service:id,name')
            ->when($upcoming, fn (Builder $query) => $query
                ->whereIn('status', AppointmentStatus::openValues())
                ->where('ends_at', '>', now())
                ->orderBy('starts_at'))
            ->when(! $upcoming, fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('ends_at', '<=', now())
                    ->orWhereNotIn('status', AppointmentStatus::openValues()))
                ->orderByDesc('starts_at'))
            ->orderBy('id')
            ->paginate($upcoming ? 50 : 20)
            ->withQueryString();

        return PortalAppointmentResource::collection($appointments);
    }

    /** Moves the appointment to another free time (`starts_at`, a UTC moment from the free times). */
    public function reschedule(Request $request, PortalContext $context, RescheduleAppointment $reschedule, int $appointment): PortalAppointmentResource
    {
        $own = self::forClient($context)->findOrFail($appointment);
        $data = $request->validate(['starts_at' => BookAppointmentRequest::startRules()]);

        return PortalAppointmentResource::make(
            $reschedule->handle($context, self::user(), $own, BookAppointmentRequest::moment($data['starts_at'])),
        );
    }

    /** Cancels the appointment (or withdraws a request), with an optional message for the astrologer. */
    public function cancel(Request $request, PortalContext $context, CancelPortalAppointment $cancel, int $appointment): PortalAppointmentResource
    {
        $own = self::forClient($context)->findOrFail($appointment);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return PortalAppointmentResource::make($cancel->handle($context, self::user(), $own, $data['reason'] ?? null));
    }

    /**
     * The client's appointments — in the practice the tenant scope is set to,
     * and of the one client record the portal link opens.
     *
     * @return Builder<Appointment>
     */
    public static function forClient(PortalContext $context): Builder
    {
        return Appointment::query()->where('client_id', $context->client()->getKey());
    }

    private static function user(): PortalUser
    {
        /** @var PortalUser */
        return Auth::guard('portal')->user();
    }
}
