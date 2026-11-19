<?php

namespace App\Support\Notifications;

use App\Models\Workspace;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * The frame an email is shown in (resources/views/vendor/notifications/email.blade.php and
 * vendor/mail/*): AstroLabe's name in the header, and a link to where its reader chooses
 * their notifications. A client's email is the practice's: its logo — a raster one, many
 * mail programs show no SVG — or its name, and it is signed with the practice's name;
 * without either, AstroLabe's (user, 19 Nov 2026).
 */
final class MailLook
{
    /** An astrologer's email: their own notification settings in the app. */
    public static function forAstrologer(MailMessage $mail): MailMessage
    {
        return self::with($mail, ['settingsUrl' => url('/settings/notifications')]);
    }

    /** A client's email: the practice's header and signature, their Profile in the portal. */
    public static function forClient(MailMessage $mail, ?int $workspaceId, ?string $practiceName = null, bool $settings = true): MailMessage
    {
        $workspace = $workspaceId === null ? null : Workspace::query()->find($workspaceId);
        $name = $practiceName ?? $workspace?->portalName();

        return self::with($mail, array_filter([
            'brand' => $name === null ? null : [
                'name' => $name,
                'logo' => $workspace === null ? null : self::logo($workspace),
                'url' => config('portal.url'),
            ],
            'settingsUrl' => $settings ? config('portal.url').'/profile' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function with(MailMessage $mail, array $data): MailMessage
    {
        return $mail->markdown($mail->markdown ?? 'notifications::email', [...$mail->viewData, ...$data]);
    }

    /** A link to the practice's logo that lasts a year, for raster logos only. */
    private static function logo(Workspace $workspace): ?string
    {
        if ($workspace->logo_path === null || str_ends_with(strtolower($workspace->logo_path), '.svg')) {
            return null;
        }

        return rtrim((string) config('portal.url'), '/').URL::temporarySignedRoute('portal.logo', now()->addYear()->startOfDay(), [
            'workspace' => $workspace->getKey(),
            'v' => substr(sha1($workspace->logo_path), 0, 10),
        ], absolute: false);
    }
}
