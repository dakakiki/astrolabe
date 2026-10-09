<?php

namespace App\Support\Portal;

use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The practice's logo for the client portal (docs/spec/12, "Settings →
 * Branding"): PNG, WebP or SVG up to `portal.logo_max_kb`, square or wide.
 * The type is read from the content; an SVG is cleaned (SvgSanitizer). Stored
 * on the private attachments disk under the practice's id, under a new name
 * each time, so links to an old logo stop showing it.
 */
final class PracticeLogo
{
    /** Taller than this is neither square nor wide. */
    private const MIN_RATIO = 0.9;

    /** Wider than this would be a hairline in the portal's header. */
    private const MAX_RATIO = 8.0;

    private const TYPES = ['image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    public static function store(Workspace $workspace, UploadedFile $file): void
    {
        $content = (string) file_get_contents($file->getRealPath());
        $mime = self::detect($content);

        if ($mime === 'image/svg+xml') {
            $content = SvgSanitizer::clean($content) ?? throw self::invalid('portal.branding.logo_unreadable');
        }

        $ratio = self::ratio($content, $mime);

        if ($ratio !== null && ($ratio < self::MIN_RATIO || $ratio > self::MAX_RATIO)) {
            throw self::invalid('portal.branding.logo_shape');
        }

        $disk = Storage::disk(self::disk());
        $path = $workspace->getKey().'/branding/logo-'.Str::lower(Str::random(16)).'.'.self::TYPES[$mime];
        $disk->put($path, $content);

        $previous = $workspace->logo_path;
        $workspace->forceFill(['logo_path' => $path])->save();

        if ($previous !== null) {
            $disk->delete($previous);
        }
    }

    public static function remove(Workspace $workspace): void
    {
        if ($workspace->logo_path === null) {
            return;
        }

        Storage::disk(self::disk())->delete($workspace->logo_path);
        $workspace->forceFill(['logo_path' => null])->save();
    }

    /**
     * The logo itself: nothing in it may run (sandbox, nosniff), and browsers
     * may keep it for a day — a new logo has a new name.
     */
    public static function response(Workspace $workspace): StreamedResponse
    {
        $disk = Storage::disk(self::disk());

        abort_if($workspace->logo_path === null || ! $disk->exists($workspace->logo_path), 404);

        $mime = array_search(pathinfo($workspace->logo_path, PATHINFO_EXTENSION), self::TYPES, true) ?: 'application/octet-stream';

        return $disk->response($workspace->logo_path, 'logo.'.pathinfo($workspace->logo_path, PATHINFO_EXTENSION), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    /**
     * A link the portal can show without a session — on the invitation page and
     * the practice choice — signed and the same for a whole day.
     */
    public static function portalUrl(Workspace $workspace): ?string
    {
        if ($workspace->logo_path === null) {
            return null;
        }

        return URL::temporarySignedRoute('portal.logo', now()->addDays(config('portal.logo_link_days'))->startOfDay(), [
            'workspace' => $workspace->getKey(),
            'v' => substr(sha1($workspace->logo_path), 0, 10),
        ], absolute: false);
    }

    /** For the astrologer (Settings → Branding), through the signed-in API. */
    public static function appUrl(Workspace $workspace): ?string
    {
        return $workspace->logo_path === null
            ? null
            : '/api/v1/workspace/logo?v='.substr(sha1($workspace->logo_path), 0, 10);
    }

    private static function detect(string $content): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';

        if (isset(self::TYPES[$mime])) {
            return $mime;
        }

        // finfo reads an SVG without an XML declaration as text.
        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true) && preg_match('/<svg[\s>]/i', $content)) {
            return 'image/svg+xml';
        }

        throw self::invalid('portal.branding.logo_type');
    }

    /** Width over height, or null when the file does not say. */
    private static function ratio(string $content, string $mime): ?float
    {
        if ($mime !== 'image/svg+xml') {
            $size = @getimagesizefromstring($content);

            return $size && $size[1] > 0 ? $size[0] / $size[1] : null;
        }

        if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $content, $box) && (float) $box[2] > 0) {
            return (float) $box[1] / (float) $box[2];
        }

        if (preg_match('/<svg[^>]*\swidth\s*=\s*["\']([\d.]+)/i', $content, $w) && preg_match('/<svg[^>]*\sheight\s*=\s*["\']([\d.]+)/i', $content, $h) && (float) $h[1] > 0) {
            return (float) $w[1] / (float) $h[1];
        }

        return null;
    }

    private static function disk(): string
    {
        return config('astrolabe.attachments.disk');
    }

    private static function invalid(string $key): ValidationException
    {
        return ValidationException::withMessages(['logo' => __($key, ['size' => config('portal.logo_max_kb')])]);
    }
}
