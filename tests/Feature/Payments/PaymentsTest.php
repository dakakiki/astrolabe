<?php

namespace Tests\Feature\Payments;

use App\Enums\ConsultationStatus;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Payments, refunds and deposits through the API, and the consultation's fee
 * and billing derived from them (docs/spec/02, "Plaćanja").
 */
class PaymentsTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 5 October 2026, 12:00 in Belgrade.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->client = Client::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'first_name' => 'Ana',
            'last_name' => 'Marković',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function consultation(array $attributes = []): Consultation
    {
        return Consultation::factory()->forClient($this->client)->create($attributes + [
            'fee_amount' => 12000,
            'fee_currency' => 'EUR',
            'starts_at' => '2026-10-01 14:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pay(array $attributes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/v1/payments', $attributes + [
            'amount' => 5000,
            'currency' => 'EUR',
            'paid_on' => '2026-10-05',
            'method' => 'bank_transfer',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function billing(Consultation $consultation): array
    {
        return $this->actingAs($this->user)->getJson("/api/v1/consultations/{$consultation->id}")->assertOk()->json('data.billing');
    }

    public function test_a_payment_for_a_consultation_is_the_consultations_clients_and_goes_on_the_timeline(): void
    {
        $consultation = $this->consultation();

        $this->pay(['consultation_id' => $consultation->id, 'reference' => 'INV-7'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'payment')
            ->assertJsonPath('data.amount', 5000)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.paid_on', '2026-10-05')
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.client.id', $this->client->id)
            ->assertJsonPath('data.consultation.id', $consultation->id)
            ->assertJsonPath('data.created_by.id', $this->user->id);

        $event = ActivityEvent::withoutGlobalScopes()->where('client_id', $this->client->id)->where('event_type', 'payment')->sole();
        $this->assertSame('2026-10-05', $event->metadata['paid_on']);
        $this->assertSame(5000, $event->metadata['amount']);
        $this->assertSame($consultation->id, $event->metadata['consultation_id']);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$this->client->id}/timeline?type=payments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'payment');

        $this->assertSame([
            'status' => 'partially_paid',
            'paid' => ['amount' => 5000, 'currency' => 'EUR'],
            'refunded' => null,
            'balance' => ['amount' => 7000, 'currency' => 'EUR'],
            'owed' => true,
        ], $this->billing($consultation));
    }

    public function test_the_billing_status_follows_the_fee_and_the_money(): void
    {
        $consultation = $this->consultation();
        $this->assertSame('unpaid', $this->billing($consultation)['status']);

        $this->pay(['consultation_id' => $consultation->id, 'amount' => 12000])->assertCreated();
        $this->assertSame('paid', $this->billing($consultation)['status']);
        $this->assertFalse($this->billing($consultation)['owed']);

        $this->pay(['consultation_id' => $consultation->id, 'amount' => 12000, 'kind' => 'refund'])->assertCreated();
        $billing = $this->billing($consultation);
        $this->assertSame('refunded', $billing['status']);
        $this->assertSame(['amount' => 12000, 'currency' => 'EUR'], $billing['refunded']);
        $this->assertSame(['amount' => 0, 'currency' => 'EUR'], $billing['paid']);

        $free = $this->consultation(['fee_amount' => 0]);
        $this->assertSame('no_charge', $this->billing($free)['status']);
        $this->assertFalse($this->billing($free)['owed']);

        $noFee = $this->consultation(['fee_amount' => null, 'fee_currency' => null]);
        $this->assertNull($this->billing($noFee)['status']);
        $this->assertNull($this->billing($noFee)['balance']);
    }

    public function test_only_held_or_missed_consultations_owe_their_fee(): void
    {
        $held = $this->consultation();
        $missed = $this->consultation(['status' => ConsultationStatus::NoShow]);
        $coming = $this->consultation(['status' => ConsultationStatus::Scheduled, 'starts_at' => '2026-10-09 14:00:00']);
        $cancelled = $this->consultation(['status' => ConsultationStatus::Cancelled]);

        $this->assertTrue($this->billing($held)['owed']);
        $this->assertTrue($this->billing($missed)['owed']);
        $this->assertFalse($this->billing($coming)['owed']);
        $this->assertFalse($this->billing($cancelled)['owed']);
        $this->assertSame('unpaid', $this->billing($cancelled)['status']);

        $this->actingAs($this->user)->getJson('/api/v1/consultations?billing=owed&sort=starts_at')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.billing.status', 'unpaid')
            ->assertJsonPath('data.0.fee', ['amount' => 12000, 'currency' => 'EUR']);
    }

    public function test_a_consultation_lists_billing_in_one_query_per_page(): void
    {
        foreach (range(1, 5) as $i) {
            Payment::factory()->forConsultation($this->consultation(), $this->user)->create();
        }

        $this->actingAs($this->user);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/api/v1/consultations')
            ->assertOk()
            ->assertJsonPath('data.0.billing.paid.amount', 5000);

        $this->assertLessThan(15, $queries);
    }

    public function test_a_new_consultation_costs_what_its_service_does_unless_told_otherwise(): void
    {
        $service = Service::factory()->inWorkspace($this->user->current_workspace_id)->create(['price_amount' => 9000, 'currency' => 'CHF']);
        $base = ['client_id' => $this->client->id, 'service_id' => $service->id, 'status' => 'completed', 'starts_at' => '2026-10-05T09:00'];

        $this->actingAs($this->user)->postJson('/api/v1/consultations', $base)
            ->assertCreated()
            ->assertJsonPath('data.fee', ['amount' => 9000, 'currency' => 'CHF'])
            ->assertJsonPath('data.billing.status', 'unpaid');

        $this->actingAs($this->user)->postJson('/api/v1/consultations', $base + ['fee' => ['amount' => 0, 'currency' => 'CHF']])
            ->assertCreated()
            ->assertJsonPath('data.fee.amount', 0)
            ->assertJsonPath('data.billing.status', 'no_charge');

        $this->actingAs($this->user)->postJson('/api/v1/consultations', $base + ['fee' => null])
            ->assertCreated()
            ->assertJsonPath('data.fee', null);
    }

    public function test_the_fee_keeps_the_currency_payments_were_made_in(): void
    {
        $consultation = $this->consultation();
        $this->pay(['consultation_id' => $consultation->id])->assertCreated();

        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$consultation->id}", ['fee' => ['amount' => 9000, 'currency' => 'USD']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fee.currency']);

        $this->actingAs($this->user)->patchJson("/api/v1/consultations/{$consultation->id}", ['fee' => ['amount' => 9000, 'currency' => 'EUR']])
            ->assertOk()
            ->assertJsonPath('data.billing.balance.amount', 4000);
    }

    public function test_payments_for_a_consultation_are_in_its_currency(): void
    {
        $consultation = $this->consultation();

        $this->pay(['consultation_id' => $consultation->id, 'currency' => 'RSD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        // Without a fee, the first payment sets the currency.
        $open = $this->consultation(['fee_amount' => null, 'fee_currency' => null]);
        $this->pay(['consultation_id' => $open->id, 'currency' => 'RSD', 'amount' => 600000])->assertCreated();
        $this->pay(['consultation_id' => $open->id, 'currency' => 'EUR'])->assertJsonValidationErrors(['currency']);
        $this->assertSame('paid', $this->billing($open)['status']);
    }

    public function test_a_refund_gives_back_at_most_what_came_in(): void
    {
        $consultation = $this->consultation();
        $this->pay(['consultation_id' => $consultation->id, 'amount' => 3000])->assertCreated();

        $this->pay(['consultation_id' => $consultation->id, 'amount' => 3001, 'kind' => 'refund'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->pay(['consultation_id' => $consultation->id, 'amount' => 3000, 'kind' => 'refund'])->assertCreated();
    }

    public function test_the_money_must_have_arrived_and_be_the_right_clients(): void
    {
        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();
        $consultation = $this->consultation();

        // Tomorrow on the astrologer's calendar.
        $this->pay(['client_id' => $this->client->id, 'paid_on' => '2026-10-06'])->assertJsonValidationErrors(['paid_on']);
        $this->pay(['client_id' => $this->client->id, 'amount' => 0])->assertJsonValidationErrors(['amount']);
        $this->pay([])->assertJsonValidationErrors(['client_id']);
        $this->pay(['client_id' => $other->id, 'consultation_id' => $consultation->id])->assertJsonValidationErrors(['consultation_id']);

        // Just from a client, for nothing in particular.
        $this->pay(['client_id' => $other->id, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.consultation', null)
            ->assertJsonPath('data.client.id', $other->id);
    }

    public function test_a_deposit_for_an_appointment_moves_to_the_consultation_recorded_from_it(): void
    {
        $service = Service::factory()->inWorkspace($this->user->current_workspace_id)->create(['price_amount' => 12000, 'currency' => 'EUR']);
        $appointment = Appointment::factory()->forClient($this->client, $this->user)->at('2026-10-03 08:00')->create(['service_id' => $service->id]);

        $deposit = $this->pay(['appointment_id' => $appointment->id, 'amount' => 3000, 'paid_on' => '2026-09-28'])
            ->assertCreated()
            ->assertJsonPath('data.client.id', $this->client->id)
            ->assertJsonPath('data.consultation', null)
            ->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.id', $deposit)
            ->assertJsonPath('data.service.price.amount', 12000);

        $consultation = $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $this->client->id,
            'appointment_id' => $appointment->id,
            'service_id' => $service->id,
            'status' => 'completed',
            'starts_at' => '2026-10-03T10:00',
        ])->assertCreated()
            ->assertJsonPath('data.billing.status', 'partially_paid')
            ->assertJsonPath('data.billing.balance.amount', 9000)
            ->assertJsonPath('data.payments.0.id', $deposit)
            ->json('data.id');

        $this->assertSame($consultation, Payment::withoutGlobalScopes()->find($deposit)->consultation_id);
        $this->assertSame($consultation, ActivityEvent::withoutGlobalScopes()->where('event_type', 'payment')->sole()->metadata['consultation_id']);

        // Paid later for the same appointment: it is that consultation's.
        $this->pay(['appointment_id' => $appointment->id, 'consultation_id' => null, 'amount' => 9000])
            ->assertCreated()
            ->assertJsonPath('data.consultation.id', $consultation);
        $this->assertSame('paid', $this->billing(Consultation::withoutGlobalScopes()->find($consultation))['status']);
    }

    public function test_deposits_fix_the_currency_of_the_consultation_recorded_from_the_appointment(): void
    {
        $appointment = Appointment::factory()->forClient($this->client, $this->user)->at('2026-10-03 08:00')->create();
        $this->pay(['appointment_id' => $appointment->id, 'currency' => 'GBP'])->assertCreated();

        $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $this->client->id,
            'appointment_id' => $appointment->id,
            'status' => 'completed',
            'starts_at' => '2026-10-03T10:00',
            'fee' => ['amount' => 12000, 'currency' => 'EUR'],
        ])->assertJsonValidationErrors(['fee.currency']);
    }

    public function test_a_payment_can_be_corrected_and_removed(): void
    {
        $consultation = $this->consultation();
        $id = $this->pay(['consultation_id' => $consultation->id])->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/payments/{$id}", ['amount' => 12000, 'method' => 'card', 'notes' => 'Paid in full'])
            ->assertOk()
            ->assertJsonPath('data.amount', 12000)
            ->assertJsonPath('data.method', 'card')
            ->assertJsonPath('data.notes', 'Paid in full');
        $this->assertSame('paid', $this->billing($consultation)['status']);
        $this->assertSame(12000, ActivityEvent::withoutGlobalScopes()->where('event_type', 'payment')->sole()->metadata['amount']);

        $this->actingAs($this->user)->deleteJson("/api/v1/payments/{$id}")->assertNoContent();

        $this->assertSoftDeleted('payments', ['id' => $id]);
        $this->assertSame('unpaid', $this->billing($consultation)['status']);
        $this->assertSame(0, ActivityEvent::withoutGlobalScopes()->where('event_type', 'payment')->count());
        $this->actingAs($this->user)->getJson('/api/v1/payments')->assertJsonCount(0, 'data');
    }

    public function test_the_list_filters_and_adds_up_per_currency(): void
    {
        $consultation = $this->consultation();
        Payment::factory()->forConsultation($consultation, $this->user)->amount(12000)->on('2026-10-01')->create();
        Payment::factory()->forConsultation($consultation, $this->user)->amount(2000)->refund()->on('2026-10-02')->create();
        Payment::factory()->fromClient($this->client, $this->user)->amount(500000, 'RSD')->on('2026-09-15')->create(['method' => 'cash']);

        $this->actingAs($this->user)->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.paid_on', '2026-10-02')
            ->assertJsonPath('data.0.kind', 'refund')
            ->assertJsonPath('totals', [
                ['amount' => 10000, 'currency' => 'EUR'],
                ['amount' => 500000, 'currency' => 'RSD'],
            ]);

        $this->actingAs($this->user)->getJson('/api/v1/payments?from=2026-10-01&to=2026-10-31')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('totals', [['amount' => 10000, 'currency' => 'EUR']]);

        $this->actingAs($this->user)->getJson('/api/v1/payments?method=cash')->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/api/v1/payments?kind=refund')->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/api/v1/payments?search=Markovi')->assertJsonCount(3, 'data');
        $this->actingAs($this->user)->getJson("/api/v1/payments?consultation_id={$consultation->id}")->assertJsonCount(2, 'data');
    }

    public function test_the_summary_counts_this_month_last_month_this_year_and_what_is_owed(): void
    {
        $this->user->currentWorkspace->update(['default_currency' => 'RSD']);
        $owed = $this->consultation();
        Payment::factory()->forConsultation($owed, $this->user)->amount(2000)->on('2026-10-02')->create();
        Payment::factory()->fromClient($this->client, $this->user)->amount(500)->refund()->on('2026-10-03')->create();
        Payment::factory()->fromClient($this->client, $this->user)->amount(9000)->on('2026-09-30')->create();
        Payment::factory()->fromClient($this->client, $this->user)->amount(300000, 'RSD')->on('2026-01-10')->create();
        Payment::factory()->fromClient($this->client, $this->user)->amount(7000)->on('2025-12-31')->create();

        $this->actingAs($this->user)->getJson('/api/v1/payments/summary')
            ->assertOk()
            ->assertJsonPath('data.today', '2026-10-05')
            ->assertJsonPath('data.this_month', ['from' => '2026-10-01', 'to' => '2026-10-31', 'received' => [['amount' => 1500, 'currency' => 'EUR']]])
            ->assertJsonPath('data.last_month.received', [['amount' => 9000, 'currency' => 'EUR']])
            ->assertJsonPath('data.this_year.received', [
                ['amount' => 300000, 'currency' => 'RSD'],
                ['amount' => 10500, 'currency' => 'EUR'],
            ])
            ->assertJsonPath('data.outstanding', ['total' => [['amount' => 10000, 'currency' => 'EUR']], 'count' => 1]);
    }

    public function test_the_export_is_a_csv_a_spreadsheet_can_add_up(): void
    {
        $consultation = $this->consultation(['title' => 'Solar return']);
        Payment::factory()->forConsultation($consultation, $this->user)->amount(12050)->on('2026-10-01')->create(['reference' => '=HYPERLINK("x")']);
        Payment::factory()->forConsultation($consultation, $this->user)->amount(50)->refund()->on('2026-10-02')->create(['method' => null]);
        Payment::factory()->fromClient($this->client, $this->user)->amount(4900, 'JPY')->on('2026-10-03')->create(['method' => 'cash']);

        $response = $this->actingAs($this->user)->get('/api/v1/payments/export?from=2026-10-01');
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('payments-2026-10-05.csv', $response->headers->get('Content-Disposition'));

        $lines = explode("\n", trim(substr($response->streamedContent(), 3)));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $this->assertSame('Date,Client,Type,Amount,Currency,Method,Reference,For,Notes', $lines[0]);
        $this->assertSame([
            '2026-10-01', 'Ana Marković', 'Payment', '120.50', 'EUR', 'Bank transfer', '\'=HYPERLINK("x")', 'Solar return (2026-10-01)', '',
        ], str_getcsv($lines[1], escape: ''));
        $this->assertSame(['2026-10-02', 'Ana Marković', 'Refund', '-0.50', 'EUR', ''], array_slice(str_getcsv($lines[2], escape: ''), 0, 6));
        $this->assertSame(['4900', 'JPY', 'Cash'], array_slice(str_getcsv($lines[3], escape: ''), 3, 3));
    }

    public function test_the_dashboard_and_the_client_show_the_money(): void
    {
        $owed = $this->consultation();
        Payment::factory()->forConsultation($owed, $this->user)->amount(2000)->on('2026-10-02')->create();
        Payment::factory()->fromClient($this->client, $this->user)->amount(1000)->on('2026-09-20')->create();

        $this->actingAs($this->user)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.payments.received_this_month', [['amount' => 2000, 'currency' => 'EUR']])
            ->assertJsonPath('data.payments.outstanding.count', 1)
            ->assertJsonPath('data.payments.waiting.0.id', $owed->id)
            ->assertJsonPath('data.payments.waiting.0.billing.balance.amount', 10000)
            ->assertJsonPath('data.payments.waiting.0.client.id', $this->client->id);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$this->client->id}")
            ->assertOk()
            ->assertJsonPath('data.stats.paid', [['amount' => 3000, 'currency' => 'EUR']])
            ->assertJsonPath('data.stats.outstanding', [['amount' => 10000, 'currency' => 'EUR']])
            ->assertJsonPath('data.stats.owed_consultations', 1);
    }

    public function test_the_timeline_entries_are_rebuilt_from_the_payments(): void
    {
        Payment::factory()->fromClient($this->client, $this->user)->create();
        ActivityEvent::withoutGlobalScopes()->where('event_type', 'payment')->delete();

        Artisan::call('activity:rebuild');

        $this->assertSame(1, ActivityEvent::withoutGlobalScopes()->where('event_type', 'payment')->count());
    }

    public function test_every_member_records_payments_and_other_workspaces_stay_out(): void
    {
        $member = $this->memberOf($this->user);
        $this->pay(['client_id' => $this->client->id], $member)->assertCreated();

        $stranger = User::factory()->withWorkspace()->create();
        $theirs = Payment::factory()->fromClient(Client::factory()->inWorkspace($stranger->current_workspace_id)->create(), $stranger)->create();
        $theirConsultation = Consultation::factory()->forClient(Client::factory()->inWorkspace($stranger->current_workspace_id)->create())->create();

        $this->actingAs($this->user)->getJson("/api/v1/payments/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->patchJson("/api/v1/payments/{$theirs->id}", ['amount' => 1])->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/api/v1/payments/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->getJson('/api/v1/payments')->assertJsonCount(1, 'data');
        $this->pay(['consultation_id' => $theirConsultation->id])->assertJsonValidationErrors(['consultation_id']);
        $this->pay(['client_id' => $this->client->id, 'workspace_id' => $stranger->current_workspace_id])->assertCreated();
        $this->assertSame(0, Payment::withoutGlobalScopes()->where('workspace_id', $stranger->current_workspace_id)->where('client_id', $this->client->id)->count());
    }

    public function test_recording_a_payment_twice_with_one_key_records_it_once(): void
    {
        $send = fn () => $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'pay-once-1234567890')
            ->postJson('/api/v1/payments', ['client_id' => $this->client->id, 'amount' => 5000, 'currency' => 'EUR', 'paid_on' => '2026-10-05']);

        $first = $send()->assertCreated()->json('data.id');
        $this->assertSame($first, $send()->assertCreated()->json('data.id'));
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }
}
