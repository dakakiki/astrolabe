<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBookingPolicyRequest;
use App\Http\Resources\BookingPolicyResource;
use App\Models\BookingPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * How clients book in the portal (Phase 9b, Settings → Booking; docs/spec/12,
 * "Zakazivanje (9b)"): every member reads it, the owner changes it. Until the
 * owner first saves, the defaults apply and booking stays off; the row is
 * created by the first change. Changes go to the audit log by field name.
 */
class BookingPolicyController extends Controller
{
    public function show(): BookingPolicyResource
    {
        return BookingPolicyResource::make(BookingPolicy::current());
    }

    public function update(UpdateBookingPolicyRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $policy = DB::transaction(function () use ($request, $current) {
            // One row per practice: two first saves at once must not both create it.
            DB::table('workspaces')->where('id', $current->id())->lockForUpdate()->first();

            $policy = BookingPolicy::current();
            $before = $policy->only(array_keys(BookingPolicy::DEFAULTS));
            $policy->fill($request->validated());

            $fields = array_keys(array_filter(
                $policy->only(array_keys(BookingPolicy::DEFAULTS)),
                fn ($value, string $field) => $value !== $before[$field],
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($fields !== []) {
                $policy->save();

                Audit::record(AuditEvent::PracticeSettingsChanged, $current->get(), [
                    'fields' => array_map(fn (string $field) => "booking.{$field}", $fields),
                ]);
            }

            return $policy;
        });

        // A first save creates the row, but this is still an update: 200, not 201.
        return BookingPolicyResource::make($policy)->response()->setStatusCode(200);
    }
}
