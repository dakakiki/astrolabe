<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;
use ZipArchive;

/**
 * Files in private storage and authorized downloads (docs/spec/03, "Storage
 * fajlova"; docs/spec/06, "Bezbednost i privatnost").
 */
class AttachmentsTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    /** A 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->user = User::factory()->withWorkspace()->create();
        $this->client = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upload(UploadedFile $file, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->post('/api/v1/attachments', $data + [
            'client_id' => $this->client->id,
            'file' => $file,
        ], ['Accept' => 'application/json']);
    }

    private function png(string $name = 'Chart photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function docx(bool $withDocument = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString($withDocument ? 'word/document.xml' : 'data/readme.xml', '<?xml version="1.0"?><document/>');
        $zip->close();

        return file_get_contents($path);
    }

    public function test_a_file_is_stored_privately_under_an_internal_name(): void
    {
        $response = $this->upload($this->png())->assertCreated()
            ->assertJsonPath('data.kind', 'file')
            ->assertJsonPath('data.name', 'Chart photo.png')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.previewable', true)
            ->assertJsonMissingPath('data.storage_path');

        $attachment = Attachment::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        $this->assertMatchesRegularExpression(
            '#^'.$this->user->current_workspace_id.'/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#',
            $attachment->storage_path,
        );
        Storage::disk('attachments')->assertExists($attachment->storage_path);
        $this->assertSame(hash('sha256', base64_decode(self::PNG)), $attachment->checksum);
        $this->assertSame($this->user->id, $attachment->uploaded_by);
        $this->assertSame(strlen(base64_decode(self::PNG)), $response->json('data.file_size'));
    }

    public function test_the_content_must_match_the_name(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('reading.pdf', '<html><script>alert(1)</script></html>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload(UploadedFile::fake()->createWithContent('photo.jpg', self::PDF))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload(UploadedFile::fake()->createWithContent('reading.pdf', self::PDF))
            ->assertCreated()->assertJsonPath('data.mime_type', 'application/pdf');

        // Refused uploads leave nothing behind.
        Storage::disk('attachments')->assertCount('', 1, recursive: true);
    }

    public function test_types_outside_the_list_are_refused(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00"))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('chart.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>x()</script></svg>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('page.html', '<html></html>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_word_document_is_recognised_by_what_is_inside_the_archive(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('Reading.docx', $this->docx()))
            ->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->upload(UploadedFile::fake()->createWithContent('Archive.docx', $this->docx(withDocument: false)))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_the_size_limit_is_enforced(): void
    {
        config(['astrolabe.attachments.max_size_mb' => 1]);

        $this->upload(UploadedFile::fake()->create('long.pdf', 1500, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file is too large. The limit is 1 MB.']);
    }

    public function test_a_path_in_the_original_name_is_dropped(): void
    {
        $this->upload($this->png('..\\..\\secret/../evil.png'))->assertCreated()->assertJsonPath('data.name', 'evil.png');
    }

    public function test_a_link_is_recorded_without_a_file(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'kind' => 'link',
            'url' => 'https://zoom.us/rec/share/abc',
            'visibility' => 'team',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'link')
            ->assertJsonPath('data.name', 'zoom.us')
            ->assertJsonPath('data.url', 'https://zoom.us/rec/share/abc')
            ->assertJsonPath('data.download_url', null);

        $this->actingAs($this->user)->postJson('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'kind' => 'link',
            'url' => 'javascript:alert(1)',
        ])->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->actingAs($this->user)->postJson('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'url' => 'https://example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors(['file', 'url']);
    }

    public function test_files_on_a_consultation_belong_to_its_client(): void
    {
        $consultation = Consultation::factory()->forClient($this->client)->create();
        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();

        $this->upload($this->png(), ['client_id' => null, 'consultation_id' => $consultation->id])
            ->assertCreated()
            ->assertJsonPath('data.consultation_id', $consultation->id)
            ->assertJsonPath('data.client_id', $this->client->id);

        $this->upload($this->png(), ['client_id' => $other->id, 'consultation_id' => $consultation->id])
            ->assertUnprocessable()->assertJsonValidationErrors('consultation_id');

        $this->upload($this->png('Profile.png'))->assertCreated();

        $this->actingAs($this->user)->getJson("/api/v1/attachments?consultation_id={$consultation->id}")->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson("/api/v1/attachments?client_id={$this->client->id}")->assertJsonCount(2, 'data');
    }

    public function test_a_download_streams_the_file_with_safe_headers(): void
    {
        $id = $this->upload($this->png('Čart №1.png'))->json('data.id');

        $response = $this->actingAs($this->user)->get("/api/v1/attachments/{$id}/download")->assertOk();

        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''%C4%8Cart%20%E2%84%961.png", $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));

        // Images may open in the browser; nothing else may.
        $inline = $this->actingAs($this->user)->get("/api/v1/attachments/{$id}/download?inline=1");
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));

        $pdf = $this->upload(UploadedFile::fake()->createWithContent('reading.pdf', self::PDF))->json('data.id');
        $forced = $this->actingAs($this->user)->get("/api/v1/attachments/{$pdf}/download?inline=1");
        $this->assertStringStartsWith('attachment;', $forced->headers->get('Content-Disposition'));
    }

    public function test_a_bucket_answers_with_a_short_lived_signed_url(): void
    {
        $id = $this->upload($this->png())->json('data.id');

        // Production keeps files in an S3-compatible bucket; the fake disk stands in for it.
        config(['filesystems.disks.attachments.driver' => 's3']);
        Storage::disk('attachments')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options) => 'https://bucket.example/'.$path.'?expires='.$expiration->getTimestamp()
                .'&disposition='.rawurlencode($options['ResponseContentDisposition']),
        );

        $location = $this->actingAs($this->user)->get("/api/v1/attachments/{$id}/download")
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://bucket.example/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertLessThanOrEqual(now()->addMinutes(5)->getTimestamp(), (int) $query['expires']);
        $this->assertStringStartsWith('attachment;', $query['disposition']);
    }

    public function test_a_private_file_exists_only_for_whoever_uploaded_it(): void
    {
        $member = $this->memberOf($this->user);
        $private = $this->upload($this->png('Private.png'))->json('data.id');
        $team = $this->upload($this->png('Team.png'), ['visibility' => 'team'])->json('data.id');

        $this->actingAs($member)->getJson("/api/v1/attachments?client_id={$this->client->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $team)
            ->assertJsonPath('data.0.can_edit', false);

        $this->actingAs($member)->get("/api/v1/attachments/{$private}/download")->assertNotFound();
        $this->actingAs($member)->deleteJson("/api/v1/attachments/{$private}")->assertNotFound();
        $this->actingAs($member)->get("/api/v1/attachments/{$team}/download")->assertOk();
        $this->actingAs($member)->deleteJson("/api/v1/attachments/{$team}")->assertForbidden();
    }

    public function test_a_file_can_be_renamed_shared_and_deleted(): void
    {
        $id = $this->upload($this->png())->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/attachments/{$id}", ['original_name' => 'Natal chart.png', 'visibility' => 'shared_with_client'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Natal chart.png')
            ->assertJsonPath('data.visibility', 'shared_with_client');

        $path = Attachment::withoutGlobalScopes()->find($id)->storage_path;

        $this->actingAs($this->user)->deleteJson("/api/v1/attachments/{$id}")->assertNoContent();

        $this->actingAs($this->user)->getJson("/api/v1/attachments?client_id={$this->client->id}")->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->get("/api/v1/attachments/{$id}/download")->assertNotFound();
        // Kept on disk until retention rules purge deleted files (Phase 8).
        Storage::disk('attachments')->assertExists($path);
    }

    public function test_a_link_has_nothing_to_download(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/attachments', [
            'client_id' => $this->client->id,
            'kind' => 'link',
            'url' => 'https://example.com/recording',
        ])->json('data.id');

        $this->actingAs($this->user)->get("/api/v1/attachments/{$id}/download")->assertNotFound();
    }
}
