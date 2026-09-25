<?php

namespace Tests\Feature\Timeline;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * The client timeline (docs/spec/02, "Vremenska linija klijenta") and its
 * projection table (docs/spec/05, "activity_events").
 */
class TimelineTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->user = User::factory()->withWorkspace()->create();
    }

    private function newClient(): int
    {
        return $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'email' => 'ana@example.com',
            'birth' => [
                'birth_date' => '1985-07-15',
                'birth_time' => '14:30',
                'time_accuracy' => 'exact',
                'latitude' => 45.25167,
                'longitude' => 19.83694,
                'birth_timezone' => 'Europe/Belgrade',
            ],
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return list<string>
     */
    private function types(int $client, string $filter = 'all', ?User $as = null): array
    {
        return array_column(
            $this->actingAs($as ?? $this->user)->getJson("/api/v1/clients/{$client}/timeline?type={$filter}")->assertOk()->json('data'),
            'type',
        );
    }

    public function test_the_work_with_a_client_shows_up_newest_first(): void
    {
        $client = $this->newClient();

        $this->travel(1)->hours();
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart")->assertOk();

        // Recorded now, but held a week ago: it sits at its own date.
        $this->travel(1)->hours();
        $consultation = $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $client,
            'title' => 'Natal reading',
            'status' => 'completed',
            'starts_at' => now()->subWeek()->format('Y-m-d\TH:i'),
            'topics' => 'Career',
        ])->json('data.id');

        $this->travel(1)->hours();
        $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $client, 'title' => 'Follow-up', 'content' => 'Call in March'])
            ->assertCreated();

        $this->travel(1)->hours();
        $this->actingAs($this->user)->post('/api/v1/attachments', [
            'consultation_id' => $consultation,
            'file' => UploadedFile::fake()->createWithContent('worksheet.txt', 'Houses and rulers'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['file', 'note', 'chart_calculated', 'client_created', 'consultation'], $this->types($client));

        $timeline = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/timeline")->json('data');
        $this->assertSame('worksheet.txt', $timeline[0]['metadata']['name']);
        $this->assertSame($consultation, $timeline[0]['metadata']['consultation_id']);
        $this->assertSame('Call in March', $timeline[1]['metadata']['excerpt']);
        $this->assertSame($this->user->name, $timeline[1]['created_by']['name']);
        $this->assertSame('completed', $timeline[4]['metadata']['status']);
        $this->assertSame('Natal reading', $timeline[4]['summary']);
        $this->assertSame(['type' => 'consultation', 'id' => $consultation], $timeline[4]['subject']);
    }

    public function test_the_timeline_can_be_filtered_and_paginated(): void
    {
        $client = $this->newClient();

        foreach (['One', 'Two', 'Three'] as $content) {
            $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $client, 'content' => $content]);
        }

        $this->assertSame(['note', 'note', 'note'], $this->types($client, 'notes'));
        $this->assertSame(['client_created'], $this->types($client, 'profile'));

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/timeline?per_page=2")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/timeline?type=invoices")
            ->assertUnprocessable();
    }

    public function test_changes_to_the_profile_and_birth_data_are_recorded_without_their_values(): void
    {
        $client = $this->newClient();

        $this->actingAs($this->user)->patchJson("/api/v1/clients/{$client}", ['email' => 'new@example.com', 'tags' => ['VIP']])
            ->assertOk();
        // Sending the same values again is no change.
        $this->actingAs($this->user)->patchJson("/api/v1/clients/{$client}", ['email' => 'new@example.com', 'tags' => ['VIP']])
            ->assertOk();

        $this->actingAs($this->user)->putJson("/api/v1/clients/{$client}/birth-details", [
            'birth_date' => '1985-07-15',
            'birth_time' => '16:05',
            'time_accuracy' => 'rectified',
            'latitude' => 45.25167,
            'longitude' => 19.83694,
            'birth_timezone' => 'Europe/Belgrade',
        ])->assertOk();

        $this->actingAs($this->user)->postJson("/api/v1/clients/{$client}/archive")->assertOk();
        $this->actingAs($this->user)->deleteJson("/api/v1/clients/{$client}/archive")->assertOk();

        $timeline = collect($this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/timeline")->json('data'));

        $this->assertSame(
            ['client_restored', 'client_archived', 'birth_details_updated', 'client_updated', 'client_created'],
            $timeline->pluck('type')->all(),
        );
        $this->assertSame(['fields' => ['email', 'tags']], $timeline[3]['metadata']);
        $this->assertSame(['added' => false, 'fields' => ['time', 'time_accuracy']], $timeline[2]['metadata']);
        $this->assertStringNotContainsString('new@example.com', json_encode($timeline->all()));
    }

    public function test_a_changed_chart_shows_up_as_a_recalculation(): void
    {
        $client = $this->newClient();
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart");

        $this->actingAs($this->user)->patchJson('/api/v1/workspace', [
            'default_house_system' => 'placidus',
            'default_zodiac_mode' => 'sidereal',
            'default_ayanamsa' => 'lahiri',
        ])->assertOk();
        $this->travel(1)->minutes();
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart");

        $charts = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/timeline?type=charts")->json('data');

        $this->assertCount(2, $charts);
        $this->assertTrue($charts[0]['metadata']['recalculated']);
        $this->assertSame('sidereal', $charts[0]['metadata']['zodiac_mode']);
        $this->assertFalse($charts[1]['metadata']['recalculated']);
    }

    public function test_private_entries_show_only_to_their_author(): void
    {
        $member = $this->memberOf($this->user);
        $client = $this->newClient();

        $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $client, 'content' => 'Mine only']);
        $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $client, 'content' => 'For the team', 'visibility' => 'team']);

        $this->assertSame(['note', 'note', 'client_created'], $this->types($client));
        $this->assertSame(['note', 'client_created'], $this->types($client, as: $member));
    }

    public function test_an_entry_follows_its_row(): void
    {
        $client = $this->newClient();
        $id = $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $client, 'title' => 'Draft', 'content' => 'x'])
            ->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/notes/{$id}", ['title' => 'Final'])->assertOk();
        $this->assertSame('Final', ActivityEvent::withoutGlobalScopes()->where('event_type', 'note')->value('summary'));

        $this->actingAs($this->user)->deleteJson("/api/v1/notes/{$id}")->assertNoContent();
        $this->assertSame(['client_created'], $this->types($client));

        Note::withoutGlobalScopes()->withTrashed()->find($id)->restore();
        $this->assertSame(['note', 'client_created'], $this->types($client));
    }

    public function test_the_projection_is_rebuilt_from_the_tables_it_mirrors(): void
    {
        $client = $this->newClient();
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart");
        $this->actingAs($this->user)->postJson('/api/v1/consultations', [
            'client_id' => $client, 'status' => 'completed', 'starts_at' => '2026-09-01T10:00',
        ]);
        $this->actingAs($this->user)->postJson('/api/v1/notes', ['client_id' => $client, 'content' => 'x']);
        $this->actingAs($this->user)->patchJson("/api/v1/clients/{$client}", ['email' => 'changed@example.com']);

        $snapshot = fn () => ActivityEvent::withoutGlobalScopes()
            ->orderBy('event_type')->orderBy('subject_id')
            ->get(['event_type', 'subject_type', 'subject_id', 'client_id', 'occurred_at', 'metadata', 'visibility'])
            ->toArray();

        $before = $snapshot();

        // Lose the projected entries; the recorded change must survive the rebuild.
        ActivityEvent::withoutGlobalScopes()->where('event_type', '!=', 'client_updated')->delete();

        $this->artisan('activity:rebuild')->assertSuccessful();

        $this->assertEquals($before, $snapshot());
    }
}
