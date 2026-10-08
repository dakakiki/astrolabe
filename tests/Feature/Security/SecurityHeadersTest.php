<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_spa_page_has_a_content_security_policy_with_a_nonce_for_its_inline_script(): void
    {
        $response = $this->get('/login')->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);

        // The theme script runs before the app; only the nonce lets it through.
        $this->assertStringContainsString('<script nonce="'.$nonce[1].'">', $response->getContent());
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_each_page_gets_its_own_nonce(): void
    {
        $first = $this->get('/login')->headers->get('Content-Security-Policy');
        $second = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_api_responses_get_the_headers_but_no_page_policy_and_no_cors(): void
    {
        $response = $this->getJson('/api/v1/status')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/status')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_a_file_download_keeps_its_own_sandbox_policy(): void
    {
        Storage::fake('attachments');
        $user = User::factory()->withWorkspace()->create();
        $client = Client::factory()->inWorkspace($user->current_workspace_id)->create();

        $id = $this->actingAs($user)->post('/api/v1/attachments', [
            'client_id' => $client->id,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Hello'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $download = $this->actingAs($user)->get("/api/v1/attachments/{$id}/download")->assertOk();

        $this->assertStringContainsString('sandbox', $download->headers->get('Content-Security-Policy'));
        $download->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
