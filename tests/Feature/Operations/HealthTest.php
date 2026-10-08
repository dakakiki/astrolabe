<?php

namespace Tests\Feature\Operations;

use App\Astrology\Contracts\EphemerisEngine;
use App\Notifications\OperatorAlert;
use App\Support\Operations\HealthCheck;
use App\Support\Operations\OperatorAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        config(['filesystems.disks.attachments.root' => storage_path('framework/testing/disks/attachments')]);
        HealthCheck::beat();
    }

    private function alerts(): array
    {
        $sent = [];

        Notification::assertSentOnDemand(OperatorAlert::class, function (OperatorAlert $alert, array $channels, AnonymousNotifiable $to) use (&$sent) {
            $sent[] = [$to->routes['mail'], $alert->subject, $alert->lines];

            return true;
        });

        return $sent;
    }

    public function test_the_health_endpoint_answers_yes_or_no_per_part(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'status' => 'ok',
                'checks' => [
                    'database' => true,
                    'cache' => true,
                    'engine' => true,
                    'storage' => true,
                    'queue' => true,
                    'scheduler' => true,
                ],
            ]]);
    }

    public function test_a_missing_engine_or_a_silent_scheduler_fails_it(): void
    {
        $this->app->make(EphemerisEngine::class)->available = false;
        $this->travel(5)->minutes();

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'failing')
            ->assertJsonPath('data.checks.engine', false)
            ->assertJsonPath('data.checks.scheduler', false)
            ->assertJsonPath('data.checks.database', true);
    }

    public function test_a_job_waiting_too_long_means_the_worker_is_down(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->getTimestamp(),
            'created_at' => now()->subMinutes(15)->getTimestamp(),
        ]);

        $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('data.checks.queue', false);
    }

    public function test_the_command_emails_the_operator_about_failures_once_an_hour_and_when_they_end(): void
    {
        Notification::fake();
        config(['astrolabe.operator.email' => 'ops@example.com']);
        $engine = $this->app->make(EphemerisEngine::class);
        $engine->available = false;

        $this->artisan('health:check')->expectsOutputToContain('FAILING')->assertFailed();
        $this->artisan('health:check')->assertFailed();

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('ops@example.com', $alerts[0][0]);
        $this->assertStringContainsString('Health check failing', $alerts[0][1]);
        $this->assertContains('Failing: engine', $alerts[0][2]);

        $engine->available = true;
        $this->artisan('health:check')->assertSuccessful();

        $this->assertStringContainsString('passing again', $this->alerts()[1][1]);
    }

    public function test_failed_jobs_since_the_last_check_are_reported(): void
    {
        Notification::fake();
        config(['astrolabe.operator.email' => 'ops@example.com']);

        $this->artisan('health:check')->assertSuccessful(); // takes note of what is there
        DB::table('failed_jobs')->insert([
            'uuid' => 'a1', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'Boom', 'failed_at' => now(),
        ]);
        $this->artisan('health:check')->expectsOutputToContain('1 queued job(s) failed')->assertSuccessful();

        $this->assertContains('1 queued job failed since the last check.', $this->alerts()[0][2]);
    }

    public function test_nothing_is_emailed_without_an_operator_address(): void
    {
        Notification::fake();
        config(['astrolabe.operator.email' => null]);
        $this->app->make(EphemerisEngine::class)->available = false;

        $this->artisan('health:check')->assertFailed();
        OperatorAlerts::exception(new RuntimeException('Something'));

        Notification::assertNothingSent();
    }

    public function test_a_server_error_is_emailed_without_its_message_once_an_hour(): void
    {
        Notification::fake();
        config(['astrolabe.operator.email' => 'ops@example.com']);

        $error = new RuntimeException('Duplicate entry "Ana Marković" for key clients_name');
        OperatorAlerts::exception($error);
        OperatorAlerts::exception($error);

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Server error: RuntimeException', $alerts[0][1]);
        $this->assertStringContainsString('tests/Feature/Operations/HealthTest.php:', implode("\n", $alerts[0][2]));
        $this->assertStringNotContainsString('Ana', implode("\n", [$alerts[0][1], ...$alerts[0][2]]));
    }
}
