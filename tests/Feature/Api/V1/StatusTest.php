<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class StatusTest extends TestCase
{
    public function test_status_reports_a_reachable_database(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertJsonPath('data.app', config('app.name'))
            ->assertJsonPath('data.database', true)
            ->assertJsonStructure(['data' => ['app', 'laravel', 'database']]);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }
}
