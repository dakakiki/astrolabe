<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\SaveAppointment;
use App\Actions\Booking\ConfirmAppointment;
use App\Actions\Booking\ConfirmMove;
use App\Actions\Booking\DeclineAppointment;
use App\Actions\Booking\DeclineMove;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelAppointmentRequest;
use App\Http\Requests\DeclineAppointmentRequest;
use App\Http\Requests\SaveAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\Booking\BookingConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The calendar (docs/spec/10). Appointments are never deleted: they are
 * moved, marked as held or missed, or cancelled with a reason.
 *
 * Booking in the portal (Phase 9b): a request waiting for the astrologer is
 * confirmed or declined here (409 `not_requested` for anything else), and so
 * is a client's request to move a confirmed one (409 `no_move_request`). Moving
 * and cancelling tell a client who can use the portal unless `notify_client`
 * is false (AppointmentRescheduled, AppointmentCancelled).
 */
class AppointmentController extends Controller
{
    /** The longest period one request may cover: a month view with its spill-over days, or an agenda. */
    public const MAX_RANGE_DAYS = 62;

    private const RELATIONS = ['client.birthDetails', 'service', 'assignedUser', 'consultation'];

    /**
     * Appointments touching the calendar days `from`–`to` (inclusive, in the
     * astrologer's own zone), earliest first. A calendar period is short, so
     * the list is not paginated. Beside the list, `move_requests`: confirmed
     * appointments the client asked to move into the period (`proposed_starts_at`).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Appointment::class);

        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'client_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'location_type' => ['nullable', Rule::enum(LocationType::class)->only([LocationType::Online, LocationType::InPerson])],
        ]);

        $zone = $request->user()->timezone ?: 'UTC';
        $from = CarbonImmutable::parse($filters['from'], $zone)->startOfDay();
        $to = CarbonImmutable::parse($filters['to'], $zone)->addDay()->startOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to' => __('appointments.range_too_long', ['days' => self::MAX_RANGE_DAYS]),
            ]);
        }

        $appointments = $this->filtered(Appointment::query(), $filters)
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        // Confirmed appointments whose client asked to move them into this period (docs/spec/12),
        // wherever they are now: the calendar draws the time asked for as a dashed block.
        $moves = in_array($filters['status'] ?? null, [null, AppointmentStatus::Scheduled->value], true)
            ? $this->filtered(Appointment::query(), $filters)
                ->where('status', AppointmentStatus::Scheduled->value)
                ->where('proposed_starts_at', '<', $to->utc())
                ->where('proposed_ends_at', '>', $from->utc())
                ->orderBy('proposed_starts_at')
                ->orderBy('id')
                ->get()
            : collect();

        return AppointmentResource::collection($appointments)->additional([
            'move_requests' => AppointmentResource::collection($moves)->resolve($request),
        ]);
    }

    /**
     * The calendar's filters other than the period and the status.
     *
     * @param  Builder<Appointment>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Appointment>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->with(self::RELATIONS)
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['service_id'] ?? null, fn (Builder $query, int $id) => $query->where('service_id', $id))
            ->when($filters['assigned_user_id'] ?? null, fn (Builder $query, int $id) => $query->where('assigned_user_id', $id))
            ->when($filters['location_type'] ?? null, fn (Builder $query, string $type) => $query->where('location_type', $type));
    }

    /**
     * 409 with `conflicts` when the astrologer already has something then;
     * the same request with `allow_overlap: true` saves it anyway.
     */
    public function store(SaveAppointmentRequest $request, SaveAppointment $save): AppointmentResource
    {
        $appointment = $save->handle(new Appointment, $request->appointmentAttributes(), $request->boolean('allow_overlap'));

        return AppointmentResource::make($appointment)->withNotes();
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return AppointmentResource::make($appointment->load([...self::RELATIONS, 'payments']))->withNotes();
    }

    public function update(SaveAppointmentRequest $request, Appointment $appointment, SaveAppointment $save): AppointmentResource
    {
        $appointment = $save->handle(
            $appointment,
            $request->appointmentAttributes(),
            $request->boolean('allow_overlap'),
            $request->notifyClient(),
        );

        return AppointmentResource::make($appointment)->withNotes();
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, CancelAppointment $cancel): AppointmentResource
    {
        $appointment = $cancel->handle($appointment, $request->validated('reason'), AppointmentActor::Practice, $request->notifyClient());

        return AppointmentResource::make($appointment)->withNotes();
    }

    /**
     * A request booked in the portal becomes scheduled (Phase 9b).
     *
     * @throws BookingConflict 409 `not_requested`
     */
    public function confirm(Appointment $appointment, ConfirmAppointment $confirm): AppointmentResource
    {
        Gate::authorize('update', $appointment);

        return AppointmentResource::make($confirm->handle($appointment))->withNotes();
    }

    /**
     * A request booked in the portal is turned down; the reason goes to the client (Phase 9b).
     *
     * @throws BookingConflict 409 `not_requested`
     */
    public function decline(DeclineAppointmentRequest $request, Appointment $appointment, DeclineAppointment $decline): AppointmentResource
    {
        return AppointmentResource::make($decline->handle($appointment, $request->validated('reason')))->withNotes();
    }

    /**
     * The appointment goes to the time the client asked to move it to (docs/spec/12).
     * Something of the astrologer's own there meanwhile is an overlap, allowed on
     * purpose with `allow_overlap` like in the calendar.
     *
     * @throws BookingConflict 409 `no_move_request`, `move_passed`
     */
    public function confirmMove(Request $request, Appointment $appointment, ConfirmMove $confirm): AppointmentResource
    {
        Gate::authorize('update', $appointment);

        $request->validate(['allow_overlap' => ['sometimes', 'boolean']]);

        return AppointmentResource::make($confirm->handle($appointment, $request->boolean('allow_overlap')))->withNotes();
    }

    /**
     * The appointment stays at its time; the reason goes to the client (docs/spec/12).
     *
     * @throws BookingConflict 409 `no_move_request`
     */
    public function declineMove(DeclineAppointmentRequest $request, Appointment $appointment, DeclineMove $decline): AppointmentResource
    {
        return AppointmentResource::make($decline->handle($appointment, $request->validated('reason')))->withNotes();
    }
}
