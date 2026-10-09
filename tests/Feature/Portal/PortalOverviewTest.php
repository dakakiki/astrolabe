<?php

namespace Tests\Feature\Portal;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\AttachmentKind;
use App\Enums\AuditEvent;
use App\Enums\Visibility;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\PortalUser;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * What the client sees in the portal (docs/spec/12, "Šta klijent vidi";
 * acceptance criterion 3): Home, Appointments, Shared, Profile — their own,
 * and only what was explicitly shared.
 */
class PortalOverviewTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private User $astrologer;

    private Client $client;

    private PortalUser $user;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->astrologer = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->client = Client::factory()->inWorkspace($this->astrologer->current_workspace_id)->create([
            'email' => 'ana@example.com',
            'phone' => '+381 60 1234567',
        ]);
        $this->service = Service::factory()->create(['workspace_id' => $this->astrologer->current_workspace_id, 'name' => 'Natal reading']);
        $this->user = $this->portalUserFor($this->client);
    }

    private function appointment(array $attributes = []): Appointment
    {
        return Appointment::factory()->forClient($this->client, $this->astrologer)->create($attributes + [
            'service_id' => $this->service->id,
            'location_details' => 'https://meet.example.com/ana',
            'notes' => 'Internal: bring the Saturn return chart',
        ]);
    }

    private function attachment(array $attributes): Attachment
    {
        $attachment = (new Attachment)->forceFill($attributes + [
            'workspace_id' => $this->client->workspace_id,
            'client_id' => $this->client->id,
            'uploaded_by' => $this->astrologer->id,
            'attachable_type' => 'client',
            'attachable_id' => $this->client->id,
            'kind' => AttachmentKind::File,
            'visibility' => Visibility::SharedWithClient,
            'storage_disk' => 'attachments',
            'mime_type' => 'application/pdf',
            'file_size' => 8,
        ]);
        $attachment->save();

        return $attachment;
    }

    public function test_home_shows_the_practice_the_next_appointment_and_new_shared_items(): void
    {
        $this->appointment(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHour()]);
        $next = $this->appointment(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $this->appointment(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'status' => AppointmentStatus::Completed]);
        Note::factory()->forClient($this->client, $this->astrologer)->create(['visibility' => Visibility::SharedWithClient]);
        Note::factory()->forClient($this->client, $this->astrologer)->create(['visibility' => Visibility::Team]);

        $this->signInToPortal($this->user);

        $this->portal('GET', 'home')
            ->assertOk()
            ->assertJsonPath('data.practice.name', 'Vega Practice')
            ->assertJsonPath('data.next_appointment.id', $next->id)
            ->assertJsonPath('data.next_appointment.service', 'Natal reading')
            ->assertJsonPath('data.next_appointment.location_details', 'https://meet.example.com/ana')
            ->assertJsonPath('data.upcoming_count', 2)
            ->assertJsonPath('data.new_shared', 1);

        // After looking at Shared, nothing is new any more.
        $this->portal('GET', 'shared')->assertOk();
        $this->travel(1)->seconds();
        $this->portal('GET', 'home')->assertJsonPath('data.new_shared', 0)->assertJsonPath('data.shared_total', 1);
    }

    public function test_the_practice_display_name_is_what_the_client_sees(): void
    {
        $this->client->workspace->update(['display_name' => 'Vega Astrology Studio']);
        $this->signInToPortal($this->user);

        $this->portal('GET', 'home')->assertJsonPath('data.practice.name', 'Vega Astrology Studio');
        $this->portal('GET', 'session')->assertJsonPath('data.practices.0.name', 'Vega Astrology Studio');
    }

    public function test_appointments_are_split_into_upcoming_and_past_without_internal_details(): void
    {
        $upcoming = $this->appointment(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $held = $this->appointment(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour(), 'status' => AppointmentStatus::Completed]);
        $cancelled = $this->appointment([
            'starts_at' => now()->addDays(9),
            'ends_at' => now()->addDays(9)->addHour(),
            'status' => AppointmentStatus::Cancelled,
            'cancellation_reason' => 'Astrologer is ill',
            'cancelled_at' => now(),
        ]);

        $this->signInToPortal($this->user);

        $this->portal('GET', 'appointments?when=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id)
            ->assertJsonPath('data.0.upcoming', true)
            ->assertJsonPath('data.0.duration_minutes', 60)
            ->assertJsonMissingPath('data.0.notes');

        $past = $this->portal('GET', 'appointments?when=past')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$held->id, $cancelled->id], array_column($past->json('data'), 'id'));
        $this->assertNull($past->json('data.0.location_details'));

        $body = $past->getContent().$this->portal('GET', 'appointments')->getContent();
        $this->assertStringNotContainsString('Saturn return', $body);
        $this->assertStringNotContainsString('Astrologer is ill', $body);
        $this->assertStringNotContainsString('cancellation_reason', $body);
    }

    public function test_shared_lists_only_what_was_shared_with_the_client(): void
    {
        $consultation = Consultation::factory()->forClient($this->client)->create(['service_id' => $this->service->id]);
        Note::factory()->forClient($this->client, $this->astrologer)->create([
            'title' => 'Your Saturn return',
            'content' => '<p>Notes for you</p><script>alert(1)</script>',
            'visibility' => Visibility::SharedWithClient,
            'consultation_id' => $consultation->id,
        ]);
        Note::factory()->forClient($this->client, $this->astrologer)->create(['title' => 'Team only', 'visibility' => Visibility::Team]);
        Note::factory()->forClient($this->client, $this->astrologer)->create(['title' => 'Private draft', 'visibility' => Visibility::Private]);
        $this->attachment(['original_name' => 'chart.pdf', 'storage_path' => 'x/chart.pdf']);
        $this->attachment(['original_name' => 'internal.pdf', 'storage_path' => 'x/internal.pdf', 'visibility' => Visibility::Team]);
        $this->attachment(['original_name' => 'Reading recording', 'kind' => AttachmentKind::Link, 'url' => 'https://example.com/rec', 'storage_disk' => null, 'mime_type' => null, 'file_size' => null]);

        $this->signInToPortal($this->user);

        $response = $this->portal('GET', 'shared')->assertOk()->assertJsonCount(3, 'data');
        $items = collect($response->json('data'));

        $note = $items->firstWhere('type', 'note');
        $this->assertSame('Your Saturn return', $note['title']);
        $this->assertStringNotContainsString('<script', $note['content']);
        $this->assertSame('Natal reading', $note['consultation']['service']);
        $this->assertTrue($note['new']);
        $this->assertSame('chart.pdf', $items->firstWhere('type', 'file')['name']);
        $this->assertSame('https://example.com/rec', $items->firstWhere('type', 'link')['url']);

        $body = $response->getContent();
        foreach (['Team only', 'Private draft', 'internal.pdf'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }
    }

    public function test_a_shared_file_downloads_and_is_audited_but_nothing_else_does(): void
    {
        Storage::disk('attachments')->put('x/chart.pdf', '%PDF-1.4');
        Storage::disk('attachments')->put('x/internal.pdf', '%PDF-1.4');
        $shared = $this->attachment(['original_name' => 'chart.pdf', 'storage_path' => 'x/chart.pdf']);
        $team = $this->attachment(['original_name' => 'internal.pdf', 'storage_path' => 'x/internal.pdf', 'visibility' => Visibility::Team]);

        $this->signInToPortal($this->user);

        $this->portalGet("api/portal/v1/files/{$shared->id}/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('chart.pdf');
        $this->portalGet("api/portal/v1/files/{$team->id}/download")->assertNotFound();

        $this->assertSame(1, AuditLog::query()
            ->where('event', AuditEvent::PortalFileDownloaded)
            ->where('portal_user_id', $this->user->id)
            ->where('workspace_id', $this->client->workspace_id)
            ->count());
    }

    public function test_the_profile_changes_the_account_and_the_clients_phone(): void
    {
        $this->signInToPortal($this->user);

        $this->portal('GET', 'profile')
            ->assertOk()
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.phone', '+381 60 1234567')
            ->assertJsonPath('data.practice_timezone', $this->client->workspace->timezone);

        $this->portal('PUT', 'profile', [
            'name' => 'Ana',
            'timezone' => 'Europe/London',
            'locale' => 'en',
            'phone' => '+44 20 7946 0000',
            'email' => 'other@example.com',
        ])->assertOk()->assertJsonPath('data.timezone', 'Europe/London');

        $this->assertSame('Europe/London', $this->user->fresh()->timezone);
        $this->assertSame('ana@example.com', $this->user->fresh()->email);
        $this->assertSame('+44 20 7946 0000', $this->client->fresh()->phone);

        $entry = ActivityEvent::query()->withoutGlobalScopes()->where('event_type', ActivityType::ClientUpdated)->sole();
        $this->assertSame(['fields' => ['phone'], 'source' => 'portal'], $entry->metadata);
        $this->assertNull($entry->created_by);

        $this->portal('PUT', 'profile', ['timezone' => 'Mars/Olympus'])->assertUnprocessable();
    }

    /** The lists ask the same number of queries for a few items and for many (Phase 8c, performance). */
    public function test_the_lists_do_not_ask_once_per_item(): void
    {
        $this->signInToPortal($this->user);

        $add = function (int $count) {
            for ($i = 0; $i < $count; $i++) {
                $consultation = Consultation::factory()->forClient($this->client)->create(['service_id' => $this->service->id]);
                Note::factory()->forClient($this->client, $this->astrologer)->create(['visibility' => Visibility::SharedWithClient, 'consultation_id' => $consultation->id]);
                $this->attachment(['original_name' => "file-{$i}.pdf", 'storage_path' => "x/{$i}.pdf", 'attachable_type' => 'consultation', 'attachable_id' => $consultation->id]);
                $this->appointment(['starts_at' => now()->addDays($i + 1), 'ends_at' => now()->addDays($i + 1)->addHour()]);
                $this->appointment(['starts_at' => now()->subDays($i + 1), 'ends_at' => now()->subDays($i + 1)->addHour()]);
            }
        };

        $count = function (string $path) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->portal('GET', $path)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $add(2);
        // "Last seen" is written once every few minutes; get it out of the way first.
        $this->portal('GET', 'home')->assertOk();
        $few = array_map($count, ['home', 'appointments', 'appointments?when=past', 'shared']);
        $add(8);
        $many = array_map($count, ['home', 'appointments', 'appointments?when=past', 'shared']);

        $this->assertSame($few, $many);
    }
}
