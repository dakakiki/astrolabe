<?php

namespace Tests\Feature\DataLifecycle;

use App\Enums\AuditEvent;
use App\Enums\ExportStatus;
use App\Enums\RelationshipType;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientRelationship;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Payment;
use App\Models\RelatedPerson;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Deleting a client for good, at the client's request (Phase 8b): only the
 * owner, only with the name typed, everything about the client gone — files
 * from the disk too — except the money received, kept without the client.
 */
class DeleteClientTest extends TestCase
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
        $this->owner = User::factory()->withWorkspace()->create();
        $this->client = Client::factory()->inWorkspace($this->owner->current_workspace_id)
            ->create(['first_name' => 'Ana', 'last_name' => 'Marković']);
    }

    private function erase(string $confirmation, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->deleteJson("/api/v1/clients/{$this->client->id}", ['confirmation' => $confirmation]);
    }

    private function uploadFile(): string
    {
        $this->actingAs($this->owner)->post('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'file' => UploadedFile::fake()->createWithContent('Birth certificate.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertCreated();

        return DB::table('attachments')->where('client_id', $this->client->id)->value('storage_path');
    }

    public function test_only_the_owner_may_delete_a_client_for_good(): void
    {
        $member = $this->memberOf($this->owner);

        $this->erase('Ana Marković', $member)->assertForbidden();

        $this->assertModelExists($this->client);
    }

    public function test_the_full_name_must_be_typed(): void
    {
        $this->erase('Ana')->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->erase('')->assertUnprocessable()->assertJsonValidationErrors('confirmation');

        $this->assertModelExists($this->client);
    }

    public function test_case_and_spaces_do_not_matter_in_the_name(): void
    {
        $this->erase('  ana   MARKOVIĆ ')->assertNoContent();

        $this->assertDatabaseMissing('clients', ['id' => $this->client->id]);
    }

    public function test_a_client_of_another_practice_is_not_found(): void
    {
        $stranger = User::factory()->withWorkspace()->create();

        $this->erase('Ana Marković', $stranger)->assertNotFound();
    }

    public function test_everything_about_the_client_goes_and_the_money_stays_without_them(): void
    {
        $path = $this->uploadFile();
        Storage::disk('attachments')->assertExists($path);

        $consultation = Consultation::factory()->forClient($this->client)->create(['created_by' => $this->owner->id]);
        $appointment = Appointment::factory()->forClient($this->client, $this->owner)->create();
        Note::factory()->forClient($this->client, $this->owner)->create();
        Note::factory()->forClient($this->client, $this->owner)->create()->delete(); // already in the trash
        Task::factory()->forClient($this->client, $this->owner)->create();
        $payment = Payment::factory()->forConsultation($consultation, $this->owner)
            ->create(['reference' => 'Ana — bank', 'notes' => 'Paid for her reading']);
        $deposit = Payment::factory()->forAppointment($appointment, $this->owner)->amount(3000)->create();
        $trashed = Payment::factory()->fromClient($this->client, $this->owner)->create();
        $trashed->delete();
        $partner = RelatedPerson::factory()->relatedTo($this->client)->create();
        DB::table('related_person_birth_details')->insert([
            'workspace_id' => $this->client->workspace_id, 'related_person_id' => $partner->id,
            'birth_date' => '1990-01-02', 'time_accuracy' => 'unknown', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_calculations')->insert([
            ['workspace_id' => $this->client->workspace_id, 'subject_type' => 'client', 'subject_id' => $this->client->id] + $this->chartRow(),
            ['workspace_id' => $this->client->workspace_id, 'subject_type' => 'related_person', 'subject_id' => $partner->id] + $this->chartRow(),
        ]);

        $this->erase('Ana Marković')->assertNoContent();

        $this->assertDatabaseMissing('clients', ['id' => $this->client->id]);
        $this->assertDatabaseMissing('client_birth_details', ['client_id' => $this->client->id]);
        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame(0, DB::table('appointments')->count());
        $this->assertSame(0, DB::table('notes')->count());
        $this->assertSame(0, DB::table('tasks')->count());
        $this->assertSame(0, DB::table('attachments')->count());
        $this->assertSame(0, DB::table('activity_events')->count());
        $this->assertSame(0, DB::table('chart_calculations')->count());
        $this->assertSame(0, DB::table('client_relationships')->count());
        $this->assertSame(0, DB::table('related_people')->count());
        $this->assertSame(0, DB::table('related_person_birth_details')->count());
        Storage::disk('attachments')->assertMissing($path);

        // The money received stays in the practice's figures, saying nothing about who paid or for what.
        $kept = DB::table('payments')->orderBy('id')->get();
        $this->assertCount(2, $kept);
        $this->assertEquals([$payment->id, $deposit->id], $kept->pluck('id')->all());
        foreach ($kept as $row) {
            $this->assertNull($row->client_id);
            $this->assertNull($row->consultation_id);
            $this->assertNull($row->appointment_id);
            $this->assertNull($row->reference);
            $this->assertNull($row->notes);
            $this->assertNull($row->deleted_at);
        }
        $this->assertSame(['5000', '3000'], $kept->pluck('amount')->map(fn ($amount) => (string) $amount)->all());

        $this->actingAs($this->owner)->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.client', null)
            ->assertJsonPath('totals.0.amount', 8000);
    }

    public function test_the_audit_log_records_counts_but_never_the_name(): void
    {
        Consultation::factory()->forClient($this->client)->count(2)->create();
        Payment::factory()->fromClient($this->client)->create();

        $this->erase('Ana Marković')->assertNoContent();

        $entry = AuditLog::query()->where('event', AuditEvent::ClientErased)->sole();
        $this->assertSame($this->owner->id, $entry->user_id);
        $this->assertSame('client', $entry->subject_type);
        $this->assertSame($this->client->id, $entry->subject_id);
        $this->assertSame(2, $entry->properties['consultations']);
        $this->assertSame(1, $entry->properties['payments_kept']);
        $this->assertStringNotContainsString('Ana', json_encode($entry->properties));
        $this->assertStringNotContainsString('Marković', json_encode($entry->properties, JSON_UNESCAPED_UNICODE));
        // One entry, not one per row.
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::RecordDeleted)->count());
    }

    public function test_people_and_clients_linked_to_others_stay(): void
    {
        $other = Client::factory()->inWorkspace($this->client->workspace_id)->create();
        $shared = RelatedPerson::factory()->relatedTo($this->client)->create();
        $link = $other->relationships()->make(['relationship_type' => RelationshipType::Parent]);
        $link->workspace_id = $other->workspace_id;
        $link->relatedPerson()->associate($shared)->save();
        $sibling = $this->client->relationships()->make(['relationship_type' => RelationshipType::Sibling]);
        $sibling->workspace_id = $this->client->workspace_id;
        $sibling->relatedClient()->associate($other)->save();

        $this->erase('Ana Marković')->assertNoContent();

        $this->assertModelExists($other);
        $this->assertModelExists($shared);
        $this->assertSame(1, ClientRelationship::withoutGlobalScopes()->count());
        $this->assertSame($other->id, ClientRelationship::withoutGlobalScopes()->sole()->client_id);
    }

    public function test_the_record_of_a_person_who_became_this_client_goes_too(): void
    {
        $before = RelatedPerson::factory()->create(['workspace_id' => $this->client->workspace_id, 'converted_client_id' => $this->client->id]);
        $before->delete();

        $this->erase('Ana Marković')->assertNoContent();

        $this->assertDatabaseMissing('related_people', ['id' => $before->id]);
    }

    public function test_the_practice_exports_go_because_they_contain_the_client(): void
    {
        Storage::disk('exports')->put('1/export.zip', 'zip');
        $export = WorkspaceExport::withoutGlobalScopes()->forceCreate([
            'workspace_id' => $this->client->workspace_id,
            'status' => ExportStatus::Ready,
            'disk' => 'exports',
            'path' => '1/export.zip',
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->erase('Ana Marković')->assertNoContent();

        $this->assertModelMissing($export);
        Storage::disk('exports')->assertMissing('1/export.zip');
    }

    public function test_other_clients_and_practices_are_untouched(): void
    {
        $other = Client::factory()->inWorkspace($this->client->workspace_id)->create();
        Consultation::factory()->forClient($other)->create();
        Payment::factory()->fromClient($other)->create();
        $stranger = User::factory()->withWorkspace()->create();
        $theirs = Client::factory()->inWorkspace($stranger->current_workspace_id)->create();
        Consultation::factory()->forClient($theirs)->create();

        $this->erase('Ana Marković')->assertNoContent();

        $this->assertSame(2, DB::table('clients')->count());
        $this->assertSame(2, DB::table('consultations')->count());
        $this->assertSame($other->id, DB::table('payments')->value('client_id'));
    }

    public function test_a_kept_payment_can_be_removed_but_not_changed(): void
    {
        $payment = Payment::factory()->fromClient($this->client)->create();
        $this->erase('Ana Marković')->assertNoContent();

        $this->actingAs($this->owner)->patchJson("/api/v1/payments/{$payment->id}", ['amount' => 100])->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/api/v1/payments/{$payment->id}")->assertNoContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function chartRow(): array
    {
        return [
            'chart_type' => 'natal', 'input_hash' => bin2hex(random_bytes(32)), 'julian_day_ut' => 2451545,
            'zodiac_mode' => 'tropical', 'time_accuracy' => 'unknown', 'payload' => '{}', 'engine_name' => 'fake',
            'engine_version' => '1', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
