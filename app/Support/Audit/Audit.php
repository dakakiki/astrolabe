<?php

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes the audit log (docs/spec/06): who, what, when, from where — never
 * content. Properties carry names and counts (changed field names, a reason),
 * not values: no client names, no birth data, no email addresses.
 *
 * The person is the signed-in user unless given; the practice is the current
 * workspace, if any (sign-ins happen outside one). IP address and browser come
 * from the current request; a command has neither.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        AuditEvent $event,
        ?Model $subject = null,
        array $properties = [],
        ?Authenticatable $user = null,
    ): AuditLog {
        $request = request();
        $userAgent = $request->userAgent();

        return AuditLog::query()->create([
            'workspace_id' => app(CurrentWorkspace::class)->id(),
            'user_id' => ($user ?? auth()->user())?->getAuthIdentifier(),
            'event' => $event,
            'subject_type' => $subject ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'properties' => array_filter($properties, fn ($value) => $value !== null) ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    /** A stable short name ("consultation", "client_relationship"), not a class path. */
    public static function subjectType(Model $subject): string
    {
        return Str::snake(class_basename($subject));
    }
}
