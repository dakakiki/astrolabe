<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBirthDetails;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Payment;
use App\Models\RegistrationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin's Astrologers screen (Phase 8c): accounts and figures, never what
 * is inside a practice; every look recorded in the audit log.
 */
class AdminAstrologersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $astrologer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Operator']);
        $this->astrologer = User::factory()->withWorkspace(['name' => 'Vega Astrology'])->create(['name' => 'Mila Vega', 'email' => 'mila@example.com']);

        $client = Client::factory()->inWorkspace($this->astrologer->current_workspace_id)
            ->create(['first_name' => 'Ana', 'last_name' => 'Secretclient', 'internal_notes' => 'very private']);
        ClientBirthDetails::query()->forceCreate([
            'workspace_id' => $client->workspace_id, 'client_id' => $client->id, 'birth_date' => '1990-04-12',
            'birth_time' => '14:35:00', 'time_accuracy' => 'exact', 'birth_place' => 'Secretplace',
        ]);
        $consultation = Consultation::factory()->forClient($client)->create(['title' => 'Secret reading']);
        Consultation::factory()->forClient($client)->create();
        Appointment::factory()->forClient($client, $this->astrologer)->create();
        Note::factory()->forClient($client, $this->astrologer)->create(['content' => '<p>Secret note</p>']);
        Payment::factory()->forConsultation($consultation)->amount(12345)->create();
        DB::table('attachments')->insert([
            'workspace_id' => $client->workspace_id, 'client_id' => $client->id, 'attachable_type' => 'client',
            'attachable_id' => $client->id, 'kind' => 'file', 'original_name' => 'Secret.pdf', 'storage_disk' => 'attachments',
            'storage_path' => '1/x.pdf', 'file_size' => 2048, 'visibility' => 'private', 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::query()->create(['event' => AuditEvent::Login, 'user_id' => $this->astrologer->id, 'created_at' => now()->subHour()]);
    }

    public function test_the_list_shows_accounts_and_figures_but_nothing_from_inside(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/astrologers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'mila@example.com')
            ->assertJsonPath('data.0.practice.name', 'Vega Astrology')
            ->assertJsonPath('data.0.counts.clients', 1)
            ->assertJsonPath('data.0.counts.consultations', 2)
            ->assertJsonPath('data.0.counts.appointments', 1)
            ->assertJsonPath('data.0.storage_bytes', 2048)
            ->assertJsonPath('data.0.two_factor_enabled', false)
            ->assertJsonPath('data.0.last_login_at', now()->subHour()->toIso8601ZuluString());

        $body = $response->getContent();
        foreach (['Ana', 'Secretclient', 'very private', '1990-04-12', 'Secretplace', 'Secret reading', 'Secret note', '12345', 'Secret.pdf'] as $content) {
            $this->assertStringNotContainsString($content, $body);
        }
    }

    public function test_admins_are_not_in_the_list(): void
    {
        User::factory()->admin()->create(['email' => 'second-admin@example.com']);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/astrologers')
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['email' => 'second-admin@example.com']);
        $this->actingAs($this->admin)->getJson("/api/v1/admin/astrologers/{$this->admin->id}")->assertNotFound();
    }

    public function test_search_and_states_narrow_the_list(): void
    {
        User::factory()->withWorkspace()->unverified()->create(['email' => 'new@example.com']);
        User::factory()->withWorkspace()->create(['email' => 'paused@example.com', 'suspended_at' => now()]);

        $emails = fn (array $query) => collect($this->actingAs($this->admin)->getJson('/api/v1/admin/astrologers?'.http_build_query($query))->json('data'))->pluck('email')->sort()->values()->all();

        $this->assertSame(['mila@example.com'], $emails(['search' => 'vega']));
        $this->assertSame(['new@example.com'], $emails(['status' => 'unverified']));
        $this->assertSame(['paused@example.com'], $emails(['status' => 'suspended']));
        $this->astrologer->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->assertSame(['new@example.com', 'paused@example.com'], $emails(['status' => 'no_two_factor']));
    }

    public function test_the_detail_adds_the_invitation_and_memberships(): void
    {
        $invitation = RegistrationInvitation::query()->create([
            'email' => 'mila@example.com', 'token_hash' => str_repeat('a', 64), 'note' => 'From the astrology school',
            'expires_at' => now()->addDays(14), 'accepted_at' => now(), 'user_id' => $this->astrologer->id,
        ]);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/astrologers/{$this->astrologer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Mila Vega')
            ->assertJsonPath('meta.invitation.note', 'From the astrology school')
            ->assertJsonPath('meta.memberships.0.name', 'Vega Astrology')
            ->assertJsonPath('meta.memberships.0.role', 'owner');

        $this->assertModelExists($invitation);
    }

    public function test_every_look_is_in_the_audit_log(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/astrologers?search=vega')->assertOk();
        $this->actingAs($this->admin)->getJson("/api/v1/admin/astrologers/{$this->astrologer->id}")->assertOk();

        $views = AuditLog::query()->where('event', AuditEvent::AdminViewed)->orderBy('id')->get();
        $this->assertCount(2, $views);
        $this->assertSame($this->admin->id, $views[0]->user_id);
        $this->assertSame(['screen' => 'astrologers', 'filters' => ['search']], $views[0]->properties);
        $this->assertSame('user', $views[1]->subject_type);
        $this->assertSame($this->astrologer->id, $views[1]->subject_id);
    }
}
