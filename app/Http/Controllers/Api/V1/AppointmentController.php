<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\SaveAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelAppointmentRequest;
use App\Http\Requests\SaveAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
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
 */
class AppointmentController extends Controller
{
    /** The longest period one request may cover: a month view with its spill-over days, or an agenda. */
    public const MAX_RANGE_DAYS = 62;

    private const RELATIONS = ['client.birthDetails', 'service', 'assignedUser', 'consultation'];

    /**
     * Appointments touching the calendar days `from`–`to` (inclusive, in the
     * astrologer's own zone), earliest first. A calendar period is short, so
     * the list is not paginated.
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

        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['service_id'] ?? null, fn (Builder $query, int $id) => $query->where('service_id', $id))
            ->when($filters['assigned_user_id'] ?? null, fn (Builder $query, int $id) => $query->where('assigned_user_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['location_type'] ?? null, fn (Builder $query, string $type) => $query->where('location_type', $type))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return AppointmentResource::collection($appointments);
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

        return AppointmentResource::make($appointment->load(self::RELATIONS))->withNotes();
    }

    public function update(SaveAppointmentRequest $request, Appointment $appointment, SaveAppointment $save): AppointmentResource
    {
        $appointment = $save->handle($appointment, $request->appointmentAttributes(), $request->boolean('allow_overlap'));

        return AppointmentResource::make($appointment)->withNotes();
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, CancelAppointment $cancel): AppointmentResource
    {
        return AppointmentResource::make($cancel->handle($appointment, $request->validated('reason')))->withNotes();
    }
}
