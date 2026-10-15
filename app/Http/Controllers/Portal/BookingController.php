<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Booking\BookAppointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\BookAppointmentRequest;
use App\Http\Resources\Portal\PortalAppointmentResource;
use App\Http\Resources\Portal\PortalServiceResource;
use App\Models\Appointment;
use App\Models\PortalUser;
use App\Support\Booking\BookingAvailability;
use App\Support\Booking\BookingConflict;
use App\Support\Portal\PortalBooking;
use App\Support\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Portal → Book (docs/spec/12, "Zakazivanje (9b)"): what the practice offers
 * and on what terms, the free times for a service — or for moving one of the
 * client's appointments — and booking one of them.
 *
 * Free times are UTC moments; the portal shows them in the client's zone. They
 * are only ever the practice's booking astrologer's real free times (their
 * week, time off and extra time, every appointment that holds time, the
 * buffers and the policy's window), and booking checks the chosen one again
 * under the astrologer's lock (BookAppointment). Nothing tells the client why a
 * time is not free.
 */
class BookingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $policy = PortalBooking::policy($request);
        $open = PortalBooking::open($request);

        return response()->json(['data' => [
            'enabled' => $open,
            'requires_confirmation' => $policy->requires_confirmation,
            'client_note' => $policy->client_note,
            'reschedule_until_hours' => $policy->client_reschedule_until_hours,
            'cancel_until_hours' => $policy->client_cancel_until_hours,
            'max_days_ahead' => $policy->max_days_ahead,
            'min_notice_minutes' => $policy->min_notice_minutes,
            'services' => $open
                ? PortalServiceResource::collection(PortalBooking::services()->orderBy('name')->orderBy('id')->get())->resolve($request)
                : [],
        ]]);
    }

    /**
     * Free starts for a service (`service_id`), or for moving one of the client's
     * appointments (`appointment_id`: its own length, its own time counting as
     * free, its current start left out) — the policy's whole window, soonest first.
     */
    public function slots(Request $request, PortalContext $context): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required_without:appointment_id', 'prohibits:appointment_id', 'integer'],
            'appointment_id' => ['required_without:service_id', 'integer'],
        ]);

        $now = CarbonImmutable::now();

        if (isset($data['appointment_id'])) {
            $appointment = AppointmentController::forClient($context)->findOrFail($data['appointment_id']);
            $availability = self::availability($request);
            self::ensureMovable($request, $appointment, $now);
            $minutes = $appointment->durationMinutes();
            $starts = $availability->slotsToMove($appointment, $now);
        } else {
            $minutes = PortalBooking::services()->findOrFail($data['service_id'])->duration_minutes;
            $starts = self::availability($request)->slots($minutes, $now);
        }

        $policy = PortalBooking::policy($request);

        return response()->json(['data' => [
            'duration_minutes' => $minutes,
            'from' => $policy->earliestStart($now)->toIso8601ZuluString(),
            'to' => $policy->latestStart($now)->toIso8601ZuluString(),
            'slots' => BookingConflict::slotList($starts, $minutes),
        ]]);
    }

    /** Books a free time for the client: 201 scheduled, or requested when the practice confirms by hand. */
    public function store(BookAppointmentRequest $request, PortalContext $context, BookAppointment $book): JsonResponse
    {
        $service = PortalBooking::services()->findOrFail($request->integer('service_id'));

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();

        $appointment = $book->handle($context, $user, $service, $request->start(), $request->location(), $request->clientNote());

        return PortalAppointmentResource::make($appointment)->response($request)->setStatusCode(201);
    }

    /** 403 `booking_disabled` while the practice takes no bookings in the portal. */
    private static function availability(Request $request): BookingAvailability
    {
        return PortalBooking::availability($request) ?? throw BookingConflict::disabled();
    }

    /**
     * The same checks, in the same order, as moving it (RescheduleAppointment):
     * still open, before the policy's deadline, and the booking astrologer's.
     */
    private static function ensureMovable(Request $request, Appointment $appointment, CarbonImmutable $now): void
    {
        if (! $appointment->status->isOpen()) {
            throw BookingConflict::notChangeable();
        }

        if (! PortalBooking::policy($request)->clientMayReschedule($appointment, $now)) {
            throw BookingConflict::tooLate();
        }

        if ((int) $appointment->assigned_user_id !== PortalBooking::astrologerId($request)) {
            throw BookingConflict::notChangeable();
        }
    }
}
