<?php

namespace Tests\Feature\Portal;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Portal\PracticeLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * Settings → Branding (docs/spec/12; acceptance criterion 7): the practice's
 * display name, logo and one colour, as the portal shows them.
 */
class PortalBrandingTest extends TestCase
{
    use AddsWorkspaceMembers, InteractsWithPortal, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->owner = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->findOrFail($this->owner->current_workspace_id);
    }

    private function svg(string $body, string $viewBox = '0 0 200 100'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="'.$viewBox.'">'.$body.'</svg>');
    }

    public function test_the_owner_sets_the_display_name_and_colour(): void
    {
        $this->actingAs($this->owner)->patchJson('/api/v1/workspace', ['display_name' => 'Vega Studio', 'brand_color' => '#3B46A8'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Vega Studio')
            ->assertJsonPath('data.brand_color', '#3b46a8');

        $this->actingAs($this->owner)->patchJson('/api/v1/workspace', ['brand_color' => 'blue'])->assertUnprocessable()->assertJsonValidationErrors('brand_color');
        $this->actingAs($this->owner)->patchJson('/api/v1/workspace', ['display_name' => null, 'brand_color' => null])
            ->assertOk()
            ->assertJsonPath('data.display_name', null)
            ->assertJsonPath('data.brand_color', null);
    }

    public function test_a_member_cannot_change_the_branding(): void
    {
        $member = $this->memberOf($this->owner);

        $this->actingAs($member)->patchJson('/api/v1/workspace', ['display_name' => 'Mine'])->assertForbidden();
        $this->actingAs($member)->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 100)], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_png_logo_is_stored_shown_and_replaced(): void
    {
        $url = $this->actingAs($this->owner)
            ->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.logo_url');

        $first = $this->workspace()->logo_path;
        $this->assertStringStartsWith($this->owner->current_workspace_id.'/branding/logo-', $first);
        Storage::disk('attachments')->assertExists($first);

        $this->actingAs($this->owner)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->image('new.png', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('attachments')->assertMissing($first);

        $this->actingAs($this->owner)->deleteJson('/api/v1/workspace/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertNull($this->workspace()->logo_path);
        $this->assertSame(3, AuditLog::query()->where('event', AuditEvent::PracticeSettingsChanged)->count());
    }

    public function test_a_tall_logo_or_another_kind_of_file_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->image('tall.png', 100, 300)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.txt', 'just text')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->image('big.png')->size(2048)], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertNull($this->workspace()->logo_path);
    }

    public function test_an_svg_logo_is_cleaned_before_it_is_stored(): void
    {
        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => $this->svg(
            '<script>alert(1)</script>'
            .'<path d="M0 0h10v10z" fill="#123456" onclick="alert(2)"/>'
            .'<foreignObject><div>html</div></foreignObject>'
            .'<use href="https://evil.example/x.svg#a"/>'
            .'<a xlink:href="javascript:alert(3)"><rect width="5" height="5"/></a>'
            .'<rect style="fill:url(https://evil.example/p)" width="1" height="1"/>'
        )], ['Accept' => 'application/json'])->assertOk();

        $stored = Storage::disk('attachments')->get($this->workspace()->logo_path);

        $this->assertStringContainsString('<path d="M0 0h10v10z" fill="#123456"/>', $stored);
        foreach (['script', 'onclick', 'foreignObject', 'evil.example', 'javascript:', '<a '] as $removed) {
            $this->assertStringNotContainsString($removed, $stored);
        }

        $this->actingAs($this->owner)->get(PracticeLogo::appUrl($this->workspace()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    public function test_an_svg_with_entities_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&x;</text></svg>');

        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }

    public function test_the_portal_shows_the_logo_through_a_signed_link(): void
    {
        $this->actingAs($this->owner)->post('/api/v1/workspace/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->workspace()->update(['brand_color' => '#aa3355']);
        $client = Client::factory()->inWorkspace($this->owner->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->signInToPortal($this->portalUserFor($client));

        $practice = $this->portal('GET', 'session')->json('data.practices.0');
        $this->assertSame('#aa3355', $practice['brand_color']);
        $this->assertStringStartsWith('/api/portal/v1/practices/'.$this->owner->current_workspace_id.'/logo?', $practice['logo_url']);

        $this->forgetPortalCookie();
        $this->portalGet(ltrim($practice['logo_url'], '/'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->portalGet('api/portal/v1/practices/'.$this->owner->current_workspace_id.'/logo')->assertForbidden();
    }
}
