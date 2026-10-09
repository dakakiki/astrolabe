<?php

namespace Tests\Feature\Performance;

use App\Enums\AppointmentStatus;
use App\Enums\TimeAccuracy;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The performance pass (Phase 8c): a screen asks the database the same number
 * of times for six clients as for sixteen. A query per row (N+1) shows up here
 * as a difference — and lazy loading on a list already fails every test
 * (`Model::preventLazyLoading`, AppServiceProvider).
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $busiest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'UTC'));
        $this->owner = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->actingAs($this->owner);
    }

    /**
     * Clients, each with birth data, a tag, a held and paid consultation with a
     * note, an appointment this week and an open task.
     */
    private function addClients(int $count): void
    {
        $workspace = $this->owner->current_workspace_id;
        $service = Service::factory()->inWorkspace($workspace)->create();
        $tag = DB::table('tags')->where('workspace_id', $workspace)->value('id')
            ?? DB::table('tags')->insertGetId(['workspace_id' => $workspace, 'name' => 'vip']);

        for ($i = 0; $i < $count; $i++) {
            $client = Client::factory()->inWorkspace($workspace)->create();
            $this->busiest ??= $client;
            $client->tags()->attach($tag);
            DB::table('client_birth_details')->insert([
                'workspace_id' => $workspace, 'client_id' => $client->id, 'birth_date' => '1990-06-15',
                'birth_time' => '12:30:00', 'time_accuracy' => TimeAccuracy::Exact->value, 'birth_place' => 'Belgrade',
                'latitude' => 44.79, 'longitude' => 20.45, 'birth_timezone' => 'Europe/Belgrade',
            ]);

            $consultation = Consultation::factory()->forClient($client)->create([
                'service_id' => $service->id, 'created_by' => $this->owner->id, 'fee_amount' => 9000, 'fee_currency' => 'EUR',
            ]);
            Note::factory()->forClient($client, $this->owner)->create(['consultation_id' => $consultation->id]);
            Note::factory()->forClient($this->busiest, $this->owner)->create();
            Payment::factory()->forConsultation($consultation, $this->owner)->amount(4000)->on('2026-10-05')->create();
            Appointment::factory()->forClient($client, $this->owner)->at('2026-10-0'.(8 + $i % 2).' 1'.($i % 10).':00')->create([
                'service_id' => $service->id, 'status' => AppointmentStatus::Scheduled,
            ]);
            Task::factory()->forClient($client, $this->owner)->due('2026-10-0'.(6 + $i % 3))->create(['consultation_id' => $consultation->id]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function screens(): array
    {
        return [
            'dashboard' => '/api/v1/dashboard',
            'clients' => '/api/v1/clients',
            'clients by tag' => '/api/v1/clients?tag=vip',
            'client' => "/api/v1/clients/{$this->busiest->id}",
            'timeline' => "/api/v1/clients/{$this->busiest->id}/timeline",
            'notes' => "/api/v1/notes?client_id={$this->busiest->id}",
            'consultations' => '/api/v1/consultations',
            'owed consultations' => '/api/v1/consultations?billing=owed',
            'calendar' => '/api/v1/appointments?from=2026-10-05&to=2026-10-11',
            'tasks' => '/api/v1/tasks',
            'overdue tasks' => '/api/v1/tasks?due=overdue',
            'payments' => '/api/v1/payments',
            'payments summary' => '/api/v1/payments/summary',
        ];
    }

    /**
     * Each screen's second request: the first calculates (and keeps) the natal
     * charts the dashboard's transits read.
     *
     * @return array<string, int>
     */
    private function countQueries(): array
    {
        $counts = [];

        foreach ($this->screens() as $screen => $uri) {
            $this->getJson($uri)->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri)->assertOk();
            $counts[$screen] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    public function test_every_main_screen_asks_the_same_number_of_times_however_many_rows(): void
    {
        // Six fill every list on the dashboard, its transits included (they stop at six clients);
        // an empty list loads no relations, so it would ask less.
        $this->addClients(6);
        $few = $this->countQueries();

        $this->addClients(10);
        $many = $this->countQueries();

        $this->assertSame($few, $many);
    }

    public function test_the_payments_csv_reads_in_chunks_not_per_row(): void
    {
        $this->addClients(3);

        DB::enableQueryLog();
        $this->get('/api/v1/payments/export')->assertOk()->streamedContent();
        $queries = count(DB::getQueryLog());

        $this->assertLessThan(20, $queries);
    }
}
