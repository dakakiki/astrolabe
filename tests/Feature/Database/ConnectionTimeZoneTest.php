<?php

namespace Tests\Feature\Database;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Timestamps are UTC from the app to the database (found in the Phase 8c
 * performance pass): the session's zone is UTC whatever the server's is, so
 * TIMESTAMP columns never convert through a zone with daylight saving time.
 */
class ConnectionTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_session_runs_on_utc(): void
    {
        $this->assertSame('+00:00', DB::selectOne('select @@session.time_zone as zone')->zone);
    }

    public function test_a_moment_in_the_hour_central_europe_skips_is_stored_as_it_is(): void
    {
        // 02:30 on the last Sunday of March does not exist on Central European clocks.
        $this->travelTo(CarbonImmutable::parse('2026-03-29 02:30:00', 'UTC'));
        $client = Client::factory()->create();
        $stored = Client::query()->withoutGlobalScopes()->findOrFail($client->id);

        $this->assertSame('2026-03-29 02:30:00', $stored->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-29 02:30:00', DB::table('clients')->where('id', $client->id)->value('created_at'));
    }
}
