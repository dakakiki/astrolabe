<?php

namespace Tests\Feature\Consultations;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The chart attached to a consultation is a snapshot (docs/spec/02): it keeps
 * showing what the astrologer read from, whatever happens to the birth data later.
 */
class ConsultationChartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withWorkspace()->create();
    }

    /**
     * @param  array<string, mixed>|null  $birth
     * @return array{client: int, consultation: int}
     */
    private function consultation(?array $birth = []): array
    {
        $client = $this->actingAs($this->user)->postJson('/api/v1/clients', array_filter([
            'first_name' => 'Ana',
            'birth' => $birth === null ? null : $birth + [
                'birth_date' => '1985-07-15',
                'birth_time' => '14:30',
                'time_accuracy' => 'exact',
                'birth_place' => 'Novi Sad',
                'birth_country_code' => 'RS',
                'latitude' => 45.25167,
                'longitude' => 19.83694,
                'birth_timezone' => 'Europe/Belgrade',
            ],
        ]))->assertCreated()->json('data.id');

        $consultation = $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $client,
            'status' => 'completed',
            'starts_at' => '2026-09-01T10:00',
        ])->assertCreated()->json('data.id');

        return ['client' => $client, 'consultation' => $consultation];
    }

    public function test_the_current_chart_is_attached_with_what_produced_it(): void
    {
        ['consultation' => $id] = $this->consultation();

        $this->actingAs($this->user)->postJson("/api/v1/consultations/{$id}/chart")
            ->assertOk()
            ->assertJsonPath('data.has_chart', true)
            ->assertJsonPath('data.chart.status', 'ready')
            ->assertJsonPath('data.chart.engine.name', 'Fake engine')
            ->assertJsonCount(12, 'data.chart.positions');

        $this->actingAs($this->user)->getJson('/api/v1/consultations')->assertJsonPath('data.0.has_chart', true);
    }

    public function test_the_snapshot_survives_a_correction_of_the_birth_time(): void
    {
        ['client' => $client, 'consultation' => $id] = $this->consultation();

        $snapshot = $this->actingAs($this->user)->postJson("/api/v1/consultations/{$id}/chart")->json('data.chart');

        $this->actingAs($this->user)->putJson("/api/v1/clients/{$client}/birth-details", [
            'birth_date' => '1985-07-15',
            'birth_time' => '16:05',
            'time_accuracy' => 'rectified',
            'latitude' => 45.25167,
            'longitude' => 19.83694,
            'birth_timezone' => 'Europe/Belgrade',
        ])->assertOk();

        $current = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart")->json('data');
        $this->assertNotSame($snapshot['id'], $current['id']);
        $this->assertSame('rectified', $current['time_accuracy']);

        $this->actingAs($this->user)->getJson("/api/v1/consultations/{$id}")
            ->assertJsonPath('data.chart.id', $snapshot['id'])
            ->assertJsonPath('data.chart.time_accuracy', 'exact')
            ->assertJsonPath('data.chart.positions', $snapshot['positions']);
    }

    public function test_a_chart_is_not_attached_while_birth_data_is_incomplete(): void
    {
        ['consultation' => $id] = $this->consultation(null);

        $this->actingAs($this->user)->postJson("/api/v1/consultations/{$id}/chart")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chart')
            ->assertJsonPath('missing', ['birth_date', 'birth_time', 'location', 'timezone']);
    }

    public function test_an_engine_failure_is_reported_without_its_details(): void
    {
        ['consultation' => $id] = $this->consultation();

        $this->app->instance(EphemerisEngine::class, new class extends FakeEngine
        {
            public function calculate(ChartRequest $request): ChartResult
            {
                throw new EphemerisException('swetest: ephemeris file sepl_18.se1 not found');
            }
        });

        $response = $this->actingAs($this->user)->postJson("/api/v1/consultations/{$id}/chart")
            ->assertServiceUnavailable();

        $this->assertStringNotContainsString('swetest', $response->getContent());
    }

    public function test_the_snapshot_can_be_detached(): void
    {
        ['consultation' => $id] = $this->consultation();
        $this->actingAs($this->user)->postJson("/api/v1/consultations/{$id}/chart")->assertOk();

        $this->actingAs($this->user)->deleteJson("/api/v1/consultations/{$id}/chart")
            ->assertOk()
            ->assertJsonPath('data.has_chart', false)
            ->assertJsonPath('data.chart', null);

        // The calculation itself stays: it is history, not the consultation's to delete.
        $this->assertDatabaseCount('chart_calculations', 1);
    }
}
