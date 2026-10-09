<?php

namespace App\Support\Attachments;

use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The stored file itself, once the caller has authorized it — for the
 * astrologer (AttachmentController) and for the client in the portal. A private
 * bucket answers with a short-lived signed URL; local storage streams the file
 * without loading it into memory. Only images may open in the browser; with
 * nosniff and a sandboxing CSP nothing in a file can run on this origin.
 */
final class FileDownload
{
    public static function response(Attachment $attachment, bool $inline = false): StreamedResponse|RedirectResponse
    {
        $inline = $inline && AllowedFileTypes::showsInline($attachment->mime_type);
        $disposition = $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        $disk = Storage::disk($attachment->storage_disk);

        if (config("filesystems.disks.{$attachment->storage_disk}.driver") === 's3') {
            return redirect()->away($disk->temporaryUrl(
                $attachment->storage_path,
                now()->addMinutes(config('astrolabe.attachments.signed_url_minutes')),
                [
                    'ResponseContentType' => $attachment->mime_type,
                    'ResponseContentDisposition' => HeaderUtils::makeDisposition(
                        $disposition,
                        $attachment->original_name,
                        self::asciiName($attachment),
                    ),
                ],
            ));
        }

        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    /** Whether the browser may show it in place of a download. */
    public static function opensInline(Attachment $attachment, bool $requested): bool
    {
        return $requested && AllowedFileTypes::showsInline($attachment->mime_type);
    }

    private static function asciiName(Attachment $attachment): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|["%\/\\\\]/', '_', $attachment->original_name) ?? '';

        return $ascii !== '' ? $ascii : 'file';
    }
}
