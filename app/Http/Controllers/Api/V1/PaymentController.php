<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\SavePayment;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Support\Billing\Ledger;
use App\Support\Billing\PaymentsCsv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What clients paid the practice, and what went back (docs/spec/02,
 * "Plaćanja"). Recorded by hand; nothing is processed here.
 */
class PaymentController extends Controller
{
    private const RELATIONS = ['client', 'consultation.service', 'appointment', 'creator'];

    /**
     * Newest first. `totals` is what the filtered payments add up to, per
     * currency, refunds taken off.
     */
    public function index(Request $request, Ledger $ledger): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Payment::class);

        $query = $this->filtered($request);

        return PaymentResource::collection(
            (clone $query)
                ->with(self::RELATIONS)
                ->orderByDesc('paid_on')
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 25) ?: 25)
                ->withQueryString(),
        )->additional(['totals' => $ledger->received($query)]);
    }

    /** The same list as a CSV file, for a spreadsheet or the accountant. */
    public function export(Request $request, PaymentsCsv $csv): StreamedResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $payments = $this->filtered($request)
            ->with(self::RELATIONS)
            ->orderBy('paid_on')
            ->orderBy('id');

        return $csv->download($payments, 'payments-'.CarbonImmutable::now($request->user()->timezone ?: 'UTC')->format('Y-m-d').'.csv');
    }

    /**
     * The figures on top of the payments page, on the astrologer's calendar:
     * received this month, last month and this year, and what is outstanding.
     */
    public function summary(Request $request, Ledger $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $today = CarbonImmutable::now($request->user()->timezone ?: 'UTC')->startOfDay();
        $period = fn (CarbonImmutable $from, CarbonImmutable $to) => [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'received' => $ledger->receivedBetween($from->format('Y-m-d'), $to->format('Y-m-d')),
        ];

        return response()->json(['data' => [
            'today' => $today->format('Y-m-d'),
            'this_month' => $period($today->startOfMonth(), $today->endOfMonth()),
            'last_month' => $period($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()),
            'this_year' => $period($today->startOfYear(), $today->endOfYear()),
            'outstanding' => $ledger->outstanding(),
        ]]);
    }

    public function store(SavePaymentRequest $request, SavePayment $save): PaymentResource
    {
        return PaymentResource::make($save->handle(new Payment, $request->validated()));
    }

    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return PaymentResource::make($payment->load(self::RELATIONS));
    }

    public function update(SavePaymentRequest $request, Payment $payment, SavePayment $save): PaymentResource
    {
        return PaymentResource::make($save->handle($payment, $request->validated()));
    }

    /** A soft delete, for a payment recorded by mistake; it leaves the lists, the totals and the timeline. */
    public function destroy(Payment $payment): Response
    {
        Gate::authorize('delete', $payment);

        $payment->delete();
        $payment->client?->touchActivity();

        return response()->noContent();
    }

    /**
     * Filters shared by the list and the export. Days are on the astrologer's
     * calendar, as `paid_on` is.
     *
     * @return Builder<Payment>
     */
    private function filtered(Request $request): Builder
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'consultation_id' => ['nullable', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::enum(PaymentKind::class)],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return Payment::query()
            ->whereHas('client')
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['consultation_id'] ?? null, fn (Builder $query, int $id) => $query->where('consultation_id', $id))
            ->when($filters['appointment_id'] ?? null, fn (Builder $query, int $id) => $query->where('appointment_id', $id))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['method'] ?? null, fn (Builder $query, string $method) => $query->where('method', $method))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency', strtoupper($currency)))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('paid_on', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('paid_on', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('reference', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('client', fn (Builder $clients) => $clients
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])));
            });
    }
}
