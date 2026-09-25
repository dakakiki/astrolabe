<?php

namespace App\Actions\Payments;

use App\Enums\PaymentKind;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Records or corrects a payment (docs/spec/02, "Plaćanja"). Only the keys
 * present in the input are changed.
 *
 * - The client comes from the consultation or appointment when there is one.
 * - A payment for an appointment that already has its consultation belongs
 *   to that consultation too; one for an appointment without it is a deposit
 *   and moves over when the consultation is recorded (`claimDeposits`).
 */
class SavePayment
{
    private const FIELDS = [
        'client_id', 'consultation_id', 'appointment_id', 'kind', 'amount', 'currency', 'paid_on', 'method', 'reference', 'notes',
    ];

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Payment $payment, array $input): Payment
    {
        return DB::transaction(function () use ($payment, $input) {
            $previousClient = $payment->client_id;
            $payment->fill(Arr::only($input, self::FIELDS));

            if (! $payment->exists) {
                $payment->created_by = auth()->id();
                $payment->kind ??= PaymentKind::Payment;
            }

            // Paid for an appointment whose consultation is recorded: it is that consultation's.
            if ($payment->appointment_id !== null && $payment->consultation_id === null) {
                $payment->consultation_id = Appointment::query()->find($payment->appointment_id)?->consultation()->value('id');
            }

            // The consultation's or appointment's client, whatever was sent.
            $owner = $payment->consultation_id !== null
                ? Consultation::query()->whereKey($payment->consultation_id)->value('client_id')
                : ($payment->appointment_id !== null ? Appointment::query()->whereKey($payment->appointment_id)->value('client_id') : null);
            $payment->client_id = $owner ?? $payment->client_id;

            $payment->save();

            Client::query()->whereKey(array_filter([$payment->client_id, $previousClient]))->get()->each->touchActivity();

            return $payment->load(['client', 'consultation.service', 'appointment', 'creator']);
        });
    }

    /**
     * Deposits paid for an appointment join the consultation recorded from it.
     * Called inside the transaction that records the consultation.
     */
    public static function claimDeposits(Appointment $appointment, Consultation $consultation): void
    {
        Payment::query()
            ->where('appointment_id', $appointment->id)
            ->whereNull('consultation_id')
            ->get()
            ->each(function (Payment $payment) use ($consultation) {
                $payment->consultation_id = $consultation->id;
                $payment->save();
            });
    }
}
