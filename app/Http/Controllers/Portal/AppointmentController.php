<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Portal\PortalAppointmentResource;
use App\Models\Appointment;
use App\Support\Portal\PortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Portal → Appointments (docs/spec/12): the client's own, upcoming (soonest
 * first) or past — held, cancelled, missed (newest first). View only in 9a.
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
                ->where('status', AppointmentStatus::Scheduled->value)
                ->where('ends_at', '>', now())
                ->orderBy('starts_at'))
            ->when(! $upcoming, fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('ends_at', '<=', now())
                    ->orWhere('status', '!=', AppointmentStatus::Scheduled->value))
                ->orderByDesc('starts_at'))
            ->orderBy('id')
            ->paginate($upcoming ? 50 : 20)
            ->withQueryString();

        return PortalAppointmentResource::collection($appointments);
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
}
