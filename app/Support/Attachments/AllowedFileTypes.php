<?php

namespace App\Support\Attachments;

use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * The files a practice may upload (docs/spec/02, "Fajlovi i dokumenti").
 *
 * A file is accepted only when its extension is on the list and its content,
 * read by fileinfo, is of the kind the extension promises: a script renamed to
 * "reading.pdf" is refused. The stored type is ours, never the one the browser sent.
 */
final class AllowedFileTypes
{
    /**
     * Extension => [type stored and served, types fileinfo may report for it].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const TYPES = [
        'jpg' => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'png' => ['image/png', ['image/png']],
        'webp' => ['image/webp', ['image/webp']],
        'pdf' => ['application/pdf', ['application/pdf']],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ],
        'txt' => ['text/plain', ['text/plain']],
        'md' => ['text/markdown', ['text/plain', 'text/markdown']],
        'mp3' => ['audio/mpeg', ['audio/mpeg']],
        'm4a' => ['audio/mp4', ['audio/mp4', 'audio/x-m4a', 'video/mp4']],
        'wav' => ['audio/wav', ['audio/wav', 'audio/x-wav', 'audio/vnd.wave']],
        'ogg' => ['audio/ogg', ['audio/ogg', 'application/ogg']],
        'mp4' => ['video/mp4', ['video/mp4']],
        'mov' => ['video/quicktime', ['video/quicktime']],
        'webm' => ['video/webm', ['video/webm', 'audio/webm']],
    ];

    /** Word documents are ZIP archives; fileinfo does not always look inside. */
    private const ZIP_TYPES = ['application/zip', 'application/octet-stream'];

    /** Images the browser may show inline; everything else is always downloaded. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * The type to store for this upload, or null when it is not allowed.
     *
     * @return array{mime: string, extension: string}|null
     */
    public static function detect(UploadedFile $file): ?array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (! isset(self::TYPES[$extension]) || $path === false) {
            return null;
        }

        [$stored, $accepted] = self::TYPES[$extension];
        $actual = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';

        $matches = in_array($actual, $accepted, true)
            || ($extension === 'docx' && in_array($actual, self::ZIP_TYPES, true) && self::isWordDocument($path));

        return $matches ? ['mime' => $stored, 'extension' => $extension === 'jpeg' ? 'jpg' : $extension] : null;
    }

    public static function showsInline(?string $mime): bool
    {
        return in_array($mime, self::INLINE, true);
    }

    private static function isWordDocument(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $isWord = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $isWord;
    }
}
