<?php

namespace App\Http\Requests;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Payment;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record (POST) or correct (PATCH, only the fields sent) a payment.
 *
 * `amount` is in the currency's smallest unit (4900 = 49.00 EUR) and always
 * positive; `kind: refund` gives money back. `paid_on` is the day the money
 * arrived, on the astrologer's calendar — not in the future. A payment is for
 * a consultation, or for an appointment as a deposit, or just from a client;
 * with a consultation or appointment the client comes from it.
 */
class SavePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $payment instanceof Payment
            ? $this->user()->can('update', $payment)
            : $this->user()->can('create', Payment::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $creating = ! $this->route('payment') instanceof Payment;
        $sometimes = $creating ? [] : ['sometimes'];
        $today = CarbonImmutable::now($this->user()->timezone ?: 'UTC')->format('Y-m-d');

        return [
            'client_id' => [
                ...($creating ? ['required_without_all:consultation_id,appointment_id'] : ['sometimes']),
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'consultation_id' => [
                'nullable',
                'integer',
                Rule::exists('consultations', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'appointment_id' => [
                'nullable',
                'integer',
                Rule::exists('appointments', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'kind' => [...($creating ? ['nullable'] : ['sometimes', 'required']), Rule::enum(PaymentKind::class)],
            'amount' => [...$sometimes, 'required', 'integer', 'min:1', 'max:'.SaveServiceRequest::MAX_AMOUNT],
            'currency' => [...$sometimes, 'required', Rule::in(config('astrolabe.currencies'))],
            'paid_on' => [...$sometimes, 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The consultation, appointment and client must be one client's; a
     * consultation's payments share one currency (its fee's, if set); a refund
     * gives back at most what came in for it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $errors = $validator->errors();

            if ($errors->hasAny(['client_id', 'consultation_id', 'appointment_id', 'amount', 'currency', 'kind'])) {
                return;
            }

            $payment = $this->route('payment');
            $payment = $payment instanceof Payment ? $payment : null;

            $appointmentId = $this->has('appointment_id') ? $this->input('appointment_id') : $payment?->appointment_id;
            $appointment = $appointmentId ? Appointment::query()->find($appointmentId) : null;

            $consultationId = $this->has('consultation_id') ? $this->input('consultation_id') : $payment?->consultation_id;
            $consultation = $consultationId ? Consultation::query()->find($consultationId) : null;

            if ($appointment && $consultation && $appointment->consultation()->exists()
                && ! $appointment->consultation()->whereKey($consultation->id)->exists()) {
                $errors->add('appointment_id', __('payments.appointment_other_consultation'));

                return;
            }

            // Recorded for an appointment that already has its consultation: it is that consultation's.
            $consultation ??= $appointment?->consultation()->first();

            $clientId = $this->has('client_id') ? $this->input('client_id') : $payment?->client_id;
            $owner = $consultation?->client_id ?? $appointment?->client_id;
            $explicitClient = $this->filled('client_id') || ($payment !== null && ! $this->hasAny(['consultation_id', 'appointment_id']));

            if ($owner !== null && $explicitClient && (int) $clientId !== (int) $owner) {
                $errors->add($consultation ? 'consultation_id' : 'appointment_id', __('payments.other_client'));

                return;
            }

            if ($appointment && $consultation && $appointment->client_id !== $consultation->client_id) {
                $errors->add('appointment_id', __('payments.other_client'));

                return;
            }

            $this->checkCurrency($validator, $payment, $consultation, $appointment);
            $this->checkRefund($validator, $payment, $consultation, $appointment);
        }];
    }

    /** One currency per consultation (its fee's, else its first payment's), and per appointment. */
    private function checkCurrency(Validator $validator, ?Payment $payment, ?Consultation $consultation, ?Appointment $appointment): void
    {
        $currency = $this->input('currency', $payment?->currency);
        $others = Payment::query()->when($payment, fn ($query) => $query->whereKeyNot($payment->id));

        $expected = match (true) {
            $consultation !== null => $consultation->fee_currency
                ?? (clone $others)->where('consultation_id', $consultation->id)->value('currency'),
            $appointment !== null => (clone $others)->where('appointment_id', $appointment->id)->value('currency'),
            default => null,
        };

        if ($expected !== null && $expected !== $currency) {
            $validator->errors()->add('currency', __('payments.currency_mismatch', ['currency' => $expected]));
        }
    }

    /** A refund cannot give back more than came in for the same consultation or appointment. */
    private function checkRefund(Validator $validator, ?Payment $payment, ?Consultation $consultation, ?Appointment $appointment): void
    {
        $kind = $this->has('kind') ? PaymentKind::from($this->input('kind')) : ($payment?->kind ?? PaymentKind::Payment);

        if ($kind !== PaymentKind::Refund || ($consultation === null && $appointment === null)) {
            return;
        }

        $received = Payment::query()
            ->when($payment, fn ($query) => $query->whereKeyNot($payment->id))
            ->when(
                $consultation,
                fn ($query) => $query->where('consultation_id', $consultation->id),
                fn ($query) => $query->where('appointment_id', $appointment->id),
            )
            ->get(['kind', 'amount'])
            ->sum(fn (Payment $other) => $other->net());

        if ((int) $this->input('amount', $payment?->amount) > $received) {
            $validator->errors()->add('amount', __('payments.refund_too_large'));
        }
    }
}
