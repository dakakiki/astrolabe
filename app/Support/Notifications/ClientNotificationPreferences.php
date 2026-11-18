<?php

namespace App\Support\Notifications;

use App\Enums\PortalNotificationChannel;
use App\Models\PortalUser;

/**
 * Which notices about their appointments a client wants, and how (Portal →
 * Profile → Notifications; docs/spec/12, user's decision of 18 Nov 2026): every
 * notice has email (`mail`) and push to their devices (`push`); neither turns
 * it off — the confirmations of what the client did themselves included. The
 * invitation and the sign-in codes always come by email and are not here.
 *
 * Stored on the portal account (`portal_users.notification_preferences`), for
 * every practice. A notice not stored yet follows what the account chose before
 * (Phase 9c): `notification_channel` (email, push or both) for all of them and
 * `appointment_reminders` for the reminder.
 *
 * Push needs a device that has it on: a notice wanted only by push reaches a
 * client without one by email (ClientChannels).
 */
final readonly class ClientNotificationPreferences
{
    public const MAIL = 'mail';

    public const PUSH = 'push';

    public const CHANNELS = [self::MAIL, self::PUSH];

    /** The client's own steps first, then the practice's, then the reminder. */
    public const KINDS = [
        'booked', 'requested', 'moved_by_you', 'move_requested', 'cancelled_by_you',
        'confirmed', 'declined', 'move_confirmed', 'move_declined', 'moved', 'cancelled',
        'reminder',
    ];

    /**
     * @param  array<string, array<string, bool>>  $channels  kind => channel => on
     */
    public function __construct(public array $channels) {}

    public static function of(PortalUser $user): self
    {
        $choice = $user->notification_channel ?? PortalNotificationChannel::Email;
        $reminders = (bool) ($user->appointment_reminders ?? true);
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $channels = [];

        foreach (self::KINDS as $kind) {
            $on = $kind !== 'reminder' || $reminders;

            $channels[$kind] = [
                self::MAIL => (bool) ($stored[$kind][self::MAIL] ?? ($on && $choice->wantsEmail())),
                self::PUSH => (bool) ($stored[$kind][self::PUSH] ?? ($on && $choice->wantsPush())),
            ];
        }

        return new self($channels);
    }

    public function wants(string $kind, string $channel): bool
    {
        return $this->channels[$kind][$channel] ?? false;
    }

    public function wantsAny(string $kind): bool
    {
        return in_array(true, $this->channels[$kind] ?? [], true);
    }

    /** Whether any notice is wanted by push at all. */
    public function anyPush(): bool
    {
        return collect($this->channels)->contains(fn (array $set) => $set[self::PUSH]);
    }

    /**
     * The same with some channels changed.
     *
     * @param  array<string, array<string, bool>>  $changes
     */
    public function with(array $changes): self
    {
        $channels = $this->channels;

        foreach ($changes as $kind => $set) {
            foreach ($set as $channel => $on) {
                if (isset($channels[$kind][$channel])) {
                    $channels[$kind][$channel] = (bool) $on;
                }
            }
        }

        return new self($channels);
    }

    /** Push joins email for every notice the client gets by email — the first device switched on. */
    public function withPushBesideEmail(): self
    {
        $channels = $this->channels;

        foreach ($channels as $kind => $set) {
            $channels[$kind][self::PUSH] = $set[self::PUSH] || $set[self::MAIL];
        }

        return new self($channels);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function toArray(): array
    {
        return $this->channels;
    }
}
