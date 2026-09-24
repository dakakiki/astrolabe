<?php

namespace App\Actions\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\Visibility;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Consultation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores an uploaded file in private storage, or records a link, on a client
 * or one of their consultations (docs/spec/03, "Storage fajlova"):
 *
 * - the stored name is generated ({workspace}/{year}/{month}/{uuid}.{ext});
 *   the original name is kept only as metadata;
 * - the stored type is the one the content was verified as, not the browser's;
 * - a checksum and the uploader are recorded.
 */
class StoreAttachment
{
    /**
     * @param  array{mime: string, extension: string}  $type  from AllowedFileTypes::detect()
     */
    public function file(Client $client, ?Consultation $consultation, UploadedFile $file, array $type, Visibility $visibility): Attachment
    {
        $disk = config('astrolabe.attachments.disk');
        $directory = sprintf('%d/%s', $client->workspace_id, now()->format('Y/m'));
        $checksum = hash_file('sha256', $file->getRealPath());

        $path = Storage::disk($disk)->putFileAs($directory, $file, Str::uuid()->toString().'.'.$type['extension']);

        try {
            $attachment = $this->make($client, $consultation, AttachmentKind::File, $this->cleanName($file->getClientOriginalName(), $type['extension']), $visibility);
            $attachment->forceFill([
                'storage_disk' => $disk,
                'storage_path' => $path,
                'mime_type' => $type['mime'],
                'file_size' => $file->getSize(),
                'checksum' => $checksum,
            ])->save();
        } catch (Throwable $failure) {
            Storage::disk($disk)->delete($path);

            throw $failure;
        }

        $client->touchActivity();

        return $attachment;
    }

    public function link(Client $client, ?Consultation $consultation, string $url, ?string $title, Visibility $visibility): Attachment
    {
        $name = trim((string) $title) !== '' ? trim($title) : (parse_url($url, PHP_URL_HOST) ?: $url);

        $attachment = $this->make($client, $consultation, AttachmentKind::Link, Str::limit($name, 250, ''), $visibility);
        $attachment->forceFill(['url' => $url])->save();

        $client->touchActivity();

        return $attachment;
    }

    private function make(Client $client, ?Consultation $consultation, AttachmentKind $kind, string $name, Visibility $visibility): Attachment
    {
        $attachable = $consultation ?? $client;

        return (new Attachment)->forceFill([
            'client_id' => $client->getKey(),
            'uploaded_by' => auth()->id(),
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'kind' => $kind,
            'original_name' => $name,
            'visibility' => $visibility,
        ]);
    }

    /** The name as the browser sent it, without any path or control characters. */
    private function cleanName(string $name, string $extension): string
    {
        $name = preg_replace('#^.*[/\\\\]#u', '', $name) ?? '';
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');

        return $name === '' ? 'file.'.$extension : Str::limit($name, 250, '');
    }
}
