<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_endpoints_have_a_tighter_limit_per_person(): void
    {
        config(['astrolabe.rate_limits.engine' => 2]);
        $user = User::factory()->withWorkspace()->create();
        $other = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->getJson('/api/v1/sky')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/sky')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/sky')->assertTooManyRequests()->assertHeader('Retry-After');

        // Other endpoints still answer, and so does the engine for someone else.
        $this->actingAs($user)->getJson('/api/v1/tags')->assertOk();
        $this->actingAs($other)->getJson('/api/v1/sky')->assertOk();
    }

    public function test_every_api_call_counts_against_the_general_limit(): void
    {
        config(['astrolabe.rate_limits.api' => 3]);
        $user = User::factory()->withWorkspace()->create();

        foreach (range(1, 3) as $request) {
            $this->actingAs($user)->getJson('/api/v1/tags')->assertOk();
        }

        $this->actingAs($user)->getJson('/api/v1/tags')->assertTooManyRequests();
    }
}
