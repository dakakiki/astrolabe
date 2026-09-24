<?php

namespace App\Support\Calendar;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The astrologer already has something at that time. Overlaps are allowed
 * (docs/spec/10, decision of 24 Sep 2026: warn and allow saving), but only on
 * purpose: the request is refused with 409 and the appointments in the way,
 * and repeated with `allow_overlap: true` it is saved.
 */
class OverlappingAppointments extends RuntimeException
{
    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    public function __construct(public readonly Collection $appointments)
    {
        parent::__construct('The appointment overlaps others.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('appointments.overlap'),
            'conflicts' => $this->appointments
                ->map(fn (Appointment $appointment) => AppointmentResource::make($appointment)->resolve($request))
                ->values(),
        ], 409);
    }
}
