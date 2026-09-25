<?php

namespace Database\Factories;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => PaymentKind::Payment,
            'amount' => 5000,
            'currency' => 'EUR',
            'paid_on' => now()->format('Y-m-d'),
            'method' => PaymentMethod::BankTransfer,
        ];
    }

    /** Money from the given client, recorded by the given astrologer. */
    public function fromClient(Client $client, ?User $user = null): static
    {
        return $this->state([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
            'created_by' => $user?->id,
        ]);
    }

    /** Against a consultation, from its client. */
    public function forConsultation(Consultation $consultation, ?User $user = null): static
    {
        return $this->state([
            'workspace_id' => $consultation->workspace_id,
            'client_id' => $consultation->client_id,
            'consultation_id' => $consultation->id,
            'created_by' => $user?->id,
        ]);
    }

    /** A deposit for an appointment, from its client. */
    public function forAppointment(Appointment $appointment, ?User $user = null): static
    {
        return $this->state([
            'workspace_id' => $appointment->workspace_id,
            'client_id' => $appointment->client_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user?->id,
        ]);
    }

    /** "12.50 EUR" as 1250 in EUR. */
    public function amount(int $minorUnits, string $currency = 'EUR'): static
    {
        return $this->state(['amount' => $minorUnits, 'currency' => $currency]);
    }

    public function refund(): static
    {
        return $this->state(['kind' => PaymentKind::Refund]);
    }

    public function on(string $date): static
    {
        return $this->state(['paid_on' => $date]);
    }
}
