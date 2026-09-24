<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaRoutingTest extends TestCase
{
    public function test_root_serves_the_spa_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<div id="app"></div>', escape: false);
    }

    public function test_deep_links_serve_the_spa_shell(): void
    {
        $this->get('/clients/42/chart')
            ->assertOk()
            ->assertSee('<div id="app"></div>', escape: false);
    }

    public function test_unknown_api_paths_return_json_not_the_spa(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
