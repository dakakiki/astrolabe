<?php

namespace Tests\Feature\DataLifecycle;

use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\BookingSource;
use App\Enums\ExportStatus;
use App\Jobs\BuildPracticeExport;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBirthDetails;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Payment;
use App\Models\PortalLoginToken;
use App\Models\PortalUser;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkspaceExport;
use App\Notifications\PracticeExportReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;
use ZipArchive;

/**
 * The practice export (Phase 8b): the owner asks, a queued job builds a ZIP of
 * everything, an email brings a day-long link, and only the owner downloads.
 */
class PracticeExportTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('exports');
        Notification::fake();
        $this->owner = User::factory()->withWorkspace()->create();
        $this->client = Client::factory()->inWorkspace($this->owner->current_workspace_id)
            ->create(['first_name' => 'Ana', 'last_name' => 'Marković', 'internal_notes' => 'Prefers mornings']);
    }

    private function request(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/v1/workspace/exports');
    }

    private function export(): WorkspaceExport
    {
        return WorkspaceExport::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, string> entry name => contents
     */
    private function unzip(WorkspaceExport $export): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('exports')->path($export->path)));
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }

        $zip->close();

        return $entries;
    }

    public function test_the_owner_gets_a_zip_of_everything_in_the_practice(): void
    {
        ClientBirthDetails::query()->forceCreate([
            'workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id,
            'birth_date' => '1990-04-12', 'birth_time' => '14:35:00', 'time_accuracy' => 'exact',
            'birth_place' => 'Beograd', 'birth_country_code' => 'RS', 'latitude' => 44.804010, 'longitude' => 20.465130,
            'birth_timezone' => 'Europe/Belgrade', 'geocode_source' => 'manual',
        ]);
        $consultation = Consultation::factory()->forClient($this->client)->create(['fee_amount' => 12000, 'fee_currency' => 'EUR']);
        Note::factory()->forClient($this->client, $this->owner)->create(['content' => '<p>Saturn return</p>']);
        Payment::factory()->forConsultation($consultation, $this->owner)->create(['reference' => '=SUM(A1)']);
        $this->actingAs($this->owner)->post('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'file' => UploadedFile::fake()->createWithContent('Birth certificate.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertCreated();

        // The queue runs at once in tests, so the export is built by the time the answer comes.
        $this->request()->assertCreated()->assertJsonPath('data.status', 'ready');

        $export = $this->export();
        $this->assertSame(ExportStatus::Ready, $export->status);
        $this->assertTrue($export->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame(1, $export->counts['clients']);

        $files = $this->unzip($export);
        $this->assertArrayHasKey('README.txt', $files);

        $clients = json_decode($files['clients.json'], true);
        $this->assertSame('Ana', $clients[0]['first_name']);
        $this->assertSame('Prefers mornings', $clients[0]['internal_notes']);
        // As entered, never converted.
        $this->assertSame('1990-04-12', $clients[0]['birth_details']['birth_date']);
        $this->assertSame('14:35:00', $clients[0]['birth_details']['birth_time']);

        $this->assertSame(['amount' => 12000, 'currency' => 'EUR'], json_decode($files['consultations.json'], true)[0]['fee']);
        $this->assertSame('<p>Saturn return</p>', json_decode($files['notes.json'], true)[0]['content']);
        $this->assertSame(self::PDF, $files['files/Ana Marković ('.$this->client->id.')/Birth certificate.pdf']);
        $this->assertSame('files/Ana Marković ('.$this->client->id.')/Birth certificate.pdf', json_decode($files['files.json'], true)[0]['path']);

        // Spreadsheet files: BOM, and a formula defused.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $files['csv/payments.csv']);
        $this->assertStringContainsString("'=SUM(A1)", $files['csv/payments.csv']);
        $this->assertStringContainsString('Marković', $files['csv/clients.csv']);
        $this->assertStringContainsString('1990-04-12', $files['csv/clients.csv']);

        $practice = json_decode($files['practice.json'], true);
        $this->assertSame('astrolabe-practice-export', $practice['format']);
        $this->assertSame($this->owner->email, $practice['members'][0]['email']);

        Notification::assertSentTo($this->owner, PracticeExportReady::class);
    }

    public function test_the_export_holds_portal_booking_the_rules_and_everyones_availability(): void
    {
        $member = $this->memberOf($this->owner);
        $service = Service::factory()->inWorkspace($this->owner->current_workspace_id)->bookable()->create(['name' => 'Natal reading']);
        $this->actingAs($this->owner)->putJson('/api/v1/booking-policy', [
            'enabled' => true, 'requires_confirmation' => true, 'client_cancel_until_hours' => null, 'client_note' => 'Deposit first.',
        ])->assertOk();
        $this->actingAs($this->owner)->putJson('/api/v1/availability', [
            'timezone' => 'Europe/Belgrade',
            'rules' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
                ['weekday' => 5, 'start_time' => '20:00', 'end_time' => '24:00'],
            ],
        ])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/availability/exceptions', [
            'kind' => 'unavailable', 'all_day' => true, 'starts_at' => '2026-12-24', 'ends_at' => '2026-12-26',
            'timezone' => 'Europe/Belgrade', 'note' => 'Holidays',
        ])->assertCreated();
        $this->actingAs($member)->postJson('/api/v1/availability/exceptions', [
            'kind' => 'available', 'all_day' => false, 'starts_at' => '2026-12-28T18:00', 'ends_at' => '2026-12-28T20:00',
            'timezone' => 'Europe/London',
        ])->assertCreated();

        // Booked by the client in the portal, then declined by the practice.
        $account = PortalUser::factory()->create(['email' => 'ana.portal@example.com']);
        PortalLoginToken::issue($account, Request::create('/'));
        $declined = Appointment::factory()->forClient($this->client, $this->owner)->at('2026-12-21 09:00')->create([
            'service_id' => $service->id,
            'status' => AppointmentStatus::Cancelled,
            'booking_source' => BookingSource::Portal,
            'created_by' => null,
            'portal_user_id' => $account->id,
            'client_note' => 'Could we look at my career?',
            'cancellation_reason' => 'Away that week',
            'cancelled_at' => '2026-12-01 10:00:00',
            'cancelled_by' => AppointmentActor::Practice,
            'declined_at' => '2026-12-01 10:00:00',
        ]);

        $this->request()->assertCreated();
        $export = $this->export();
        $files = $this->unzip($export);

        $appointment = json_decode($files['appointments.json'], true)[0];
        $this->assertSame($declined->id, $appointment['id']);
        $this->assertSame('portal', $appointment['booking_source']);
        $this->assertSame($account->id, $appointment['portal_user_id']);
        $this->assertSame('Could we look at my career?', $appointment['client_note']);
        $this->assertSame('practice', $appointment['cancelled_by']);
        $this->assertSame('2026-12-01T10:00:00Z', $appointment['declined_at']);

        $this->assertTrue(json_decode($files['services.json'], true)[0]['bookable_in_portal']);

        $policy = json_decode($files['practice.json'], true)['booking_policy'];
        $this->assertTrue($policy['enabled']);
        $this->assertTrue($policy['requires_confirmation']);
        $this->assertNull($policy['client_cancel_until_hours']);
        $this->assertSame(24, $policy['client_reschedule_until_hours']);
        $this->assertSame(30, $policy['slot_step_minutes']);
        $this->assertSame('Deposit first.', $policy['client_note']);

        $availability = json_decode($files['availability.json'], true);
        $this->assertSame([$this->owner->email, $member->email], array_column($availability, 'email'));
        $this->assertSame('Europe/Belgrade', $availability[0]['timezone']);
        $this->assertSame([
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
            ['weekday' => 5, 'start_time' => '20:00', 'end_time' => '24:00'],
        ], $availability[0]['rules']);
        $this->assertSame([[
            'kind' => 'unavailable', 'starts_at' => '2026-12-23T23:00:00Z', 'ends_at' => '2026-12-26T23:00:00Z',
            'timezone' => 'Europe/Belgrade', 'all_day' => true, 'note' => 'Holidays',
        ]], $availability[0]['exceptions']);
        $this->assertSame([], $availability[1]['rules']);
        $this->assertSame('2026-12-28T18:00:00Z', $availability[1]['exceptions'][0]['starts_at']);
        $this->assertSame(2, $export->counts['availability']);
        $this->assertStringContainsString('availability.json', $files['README.txt']);
        $this->assertStringContainsString('availability.json: 2', $files['README.txt']);

        // The portal account is a number: no address, sign-in token or session goes with it.
        $everything = implode("\n", array_filter($files, fn (string $name) => str_ends_with($name, '.json'), ARRAY_FILTER_USE_KEY));
        $this->assertStringNotContainsString('ana.portal@example.com', $everything);
        $this->assertStringNotContainsString((string) PortalLoginToken::query()->value('token_hash'), $everything);
        $this->assertStringNotContainsString('token', $files['appointments.json'].$files['availability.json']);
    }

    public function test_the_export_holds_only_this_practice_and_no_deleted_rows(): void
    {
        $stranger = User::factory()->withWorkspace()->create();
        Client::factory()->inWorkspace($stranger->current_workspace_id)->create(['first_name' => 'Stranger']);
        Note::factory()->forClient($this->client, $this->owner)->create(['title' => 'Gone'])->delete();

        $this->request()->assertCreated();
        $files = $this->unzip($this->export());

        $this->assertCount(1, json_decode($files['clients.json'], true));
        $this->assertStringNotContainsString('Stranger', implode('', $files));
        $this->assertSame([], json_decode($files['notes.json'], true));
    }

    public function test_members_may_not_export_the_practice(): void
    {
        $member = $this->memberOf($this->owner);

        $this->request($member)->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/workspace/exports')->assertForbidden();
    }

    public function test_one_export_at_a_time(): void
    {
        Queue::fake();

        $this->request()->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->request()->assertStatus(409);

        Queue::assertPushed(BuildPracticeExport::class, 1);
    }

    public function test_the_owner_downloads_it_and_the_audit_log_says_so(): void
    {
        $this->request()->assertCreated();
        $export = $this->export();

        $this->actingAs($this->owner)->getJson('/api/v1/workspace/exports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $export->id)
            ->assertJsonPath('data.0.downloadable', true);

        $response = $this->actingAs($this->owner)->get("/api/v1/workspace/exports/{$export->id}/download")->assertOk();
        $this->assertStringContainsString('attachment; filename=astrolabe-export-', $response->headers->get('Content-Disposition'));

        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PracticeExportRequested)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PracticeExportDownloaded)->count());
    }

    public function test_an_expired_or_foreign_export_is_not_found(): void
    {
        $this->request()->assertCreated();
        $export = $this->export();
        $stranger = User::factory()->withWorkspace()->create();

        $this->actingAs($stranger)->getJson("/api/v1/workspace/exports/{$export->id}/download")->assertNotFound();

        $this->travel(8)->days();
        $this->actingAs($this->owner)->getJson("/api/v1/workspace/exports/{$export->id}/download")->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/v1/workspace/exports')->assertJsonCount(0, 'data');
    }

    public function test_the_email_link_works_for_a_day_and_only_signed_in(): void
    {
        $this->request()->assertCreated();
        $export = $this->export();
        $link = URL::temporarySignedRoute('practice-exports.link', now()->addHours(24), ['export' => $export->id]);

        $this->app['auth']->forgetGuards();
        $this->get($link)->assertRedirect('/login?'.http_build_query(['redirect' => parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY)]));
        $this->actingAs($this->owner)->get($link)->assertOk();
        $this->actingAs($this->owner)->get("/exports/{$export->id}/download")->assertForbidden();

        $this->travel(25)->hours();
        $this->actingAs($this->owner)->get($link)->assertForbidden();
    }

    public function test_the_ready_email_carries_a_signed_link_and_no_client_data(): void
    {
        $this->request()->assertCreated();

        Notification::assertSentTo($this->owner, PracticeExportReady::class, function (PracticeExportReady $notification) {
            $mail = $notification->toMail($this->owner);
            $this->assertStringContainsString('/exports/'.$this->export()->id.'/download?expires=', $mail->actionUrl);
            $this->assertStringContainsString('signature=', $mail->actionUrl);
            $this->assertStringNotContainsString('Ana', implode(' ', [...$mail->introLines, ...$mail->outroLines]));

            return true;
        });
    }

    public function test_a_failed_build_is_marked_failed(): void
    {
        Queue::fake();
        $this->request()->assertCreated();
        $export = $this->export();

        (new BuildPracticeExport($export->id))->failed(new RuntimeException('disk full'));

        $this->assertSame(ExportStatus::Failed, $export->refresh()->status);
        $this->request()->assertCreated();
    }
}
