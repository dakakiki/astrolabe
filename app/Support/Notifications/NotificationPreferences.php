<?php

namespace App\Support\Notifications;

use App\Notifications\Channels\InAppChannel;

/**
 * What a person wants to hear about, on which channel, and when (Settings →
 * Notifications). Stored on the user (`users.notification_preferences`) and
 * laid over the defaults, so a setting added later starts from its default
 * instead of breaking old rows. Times are wall-clock times in the user's zone.
 *
 * Since 18 Nov 2026 (docs/spec/09, "Obaveštenja"; the user's decision) every
 * notification a person may turn off has its own row with two channels: email
 * (`mail`) and in the app (`app`, the bell in the header). Off on both means
 * none. Account and security email (verifying the address, a password reset,
 * an invitation, notices from the operator) always go by email and are not here.
 *
 * Rows stored before then carried three switches — `appointment_reminders`,
 * `task_digest`, `portal_bookings` — that turned a whole kind off; they are
 * read as both channels of the kinds they stood for until `channels` is saved.
 * The same three come back in `toArray()` as "any channel on" for the screens
 * that only ask whether a kind is on at all.
 */
final readonly class NotificationPreferences
{
    /** Lead times offered for appointment reminders, in minutes. */
    public const REMINDER_CHOICES = [15, 30, 60, 120, 180, 360, 720, 1440, 2880];

    public const DEFAULT_REMINDER_MINUTES = 1440;

    public const DEFAULT_DIGEST_TIME = '08:00';

    public const DEFAULT_QUIET_HOURS = ['start' => '22:00', 'end' => '08:00'];

    /** The longest lead an appointment may ask for (API; the form offers REMINDER_CHOICES). */
    public const MAX_REMINDER_MINUTES = 10080;

    public const MAIL = 'mail';

    public const APP = 'app';

    /**
     * Every notification a person may turn off, with the channels it can take.
     * A practice that was deleted has no app to show a notice in: email only.
     */
    public const KINDS = [
        'appointment_reminder' => [self::MAIL, self::APP],
        'task_digest' => [self::MAIL, self::APP],
        'portal_booked' => [self::MAIL, self::APP],
        'portal_requested' => [self::MAIL, self::APP],
        'portal_moved' => [self::MAIL, self::APP],
        'portal_move_requested' => [self::MAIL, self::APP],
        'portal_cancelled' => [self::MAIL, self::APP],
        'export_ready' => [self::MAIL, self::APP],
        'practice_deletion_scheduled' => [self::MAIL, self::APP],
        'practice_deletion_cancelled' => [self::MAIL, self::APP],
        'practice_deleted' => [self::MAIL],
    ];

    /** The kinds each switch stored before 18 Nov 2026 stood for. */
    private const LEGACY = [
        'appointment_reminders' => ['appointment_reminder'],
        'task_digest' => ['task_digest'],
        'portal_bookings' => ['portal_booked', 'portal_requested', 'portal_moved', 'portal_move_requested', 'portal_cancelled'],
    ];

    /**
     * @param  array<string, array<string, bool>>  $channels  kind => channel => on
     */
    public function __construct(
        public array $channels,
        public int $reminderMinutes,
        public string $digestTime,
        public ?QuietHours $quietHours,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray(null);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $stored ??= [];
        $quiet = array_key_exists('quiet_hours', $stored) ? $stored['quiet_hours'] : self::DEFAULT_QUIET_HOURS;
        $saved = is_array($stored['channels'] ?? null) ? $stored['channels'] : null;
        $channels = [];

        foreach (self::KINDS as $kind => $allowed) {
            foreach ($allowed as $channel) {
                $channels[$kind][$channel] = $saved !== null
                    ? (bool) ($saved[$kind][$channel] ?? true)
                    : self::legacy($stored, $kind);
            }
        }

        return new self(
            channels: $channels,
            reminderMinutes: (int) ($stored['reminder_minutes'] ?? self::DEFAULT_REMINDER_MINUTES),
            digestTime: (string) ($stored['digest_time'] ?? self::DEFAULT_DIGEST_TIME),
            quietHours: is_array($quiet) ? new QuietHours((string) $quiet['start'], (string) $quiet['end']) : null,
        );
    }

    /**
     * An old row's switch for the kind, on when it had none.
     *
     * @param  array<string, mixed>  $stored
     */
    private static function legacy(array $stored, string $kind): bool
    {
        foreach (self::LEGACY as $key => $kinds) {
            if (in_array($kind, $kinds, true) && array_key_exists($key, $stored)) {
                return (bool) $stored[$key];
            }
        }

        return true;
    }

    /**
     * The same preferences with an old-style switch applied to every channel of
     * its kinds — the API still takes `appointment_reminders`, `task_digest` and
     * `portal_bookings` (true or false for the whole kind).
     */
    public function withLegacySwitch(string $key, bool $on): self
    {
        $channels = $this->channels;

        foreach (self::LEGACY[$key] ?? [] as $kind) {
            foreach (array_keys($channels[$kind]) as $channel) {
                $channels[$kind][$channel] = $on;
            }
        }

        return new self($channels, $this->reminderMinutes, $this->digestTime, $this->quietHours);
    }

    /** @return list<string> the old-style switches the API still takes */
    public static function legacyKeys(): array
    {
        return array_keys(self::LEGACY);
    }

    public function wants(string $kind, string $channel): bool
    {
        return $this->channels[$kind][$channel] ?? false;
    }

    /** On on at least one channel — a reminder or a morning count is planned at all. */
    public function wantsAny(string $kind): bool
    {
        return in_array(true, $this->channels[$kind] ?? [], true);
    }

    /**
     * The Laravel channels a notification of this kind goes through.
     *
     * @return list<string>
     */
    public function via(string $kind): array
    {
        return array_values(array_filter([
            $this->wants($kind, self::MAIL) ? 'mail' : null,
            $this->wants($kind, self::APP) ? InAppChannel::class : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $any = fn (array $kinds) => collect($kinds)->contains(fn (string $kind) => $this->wantsAny($kind));

        return [
            'channels' => $this->channels,
            'reminder_minutes' => $this->reminderMinutes,
            'digest_time' => $this->digestTime,
            'quiet_hours' => $this->quietHours === null ? null : [
                'start' => $this->quietHours->start,
                'end' => $this->quietHours->end,
            ],
            // Read-only summaries for screens that ask only whether a kind is on at all.
            'appointment_reminders' => $any(self::LEGACY['appointment_reminders']),
            'task_digest' => $any(self::LEGACY['task_digest']),
            'portal_bookings' => $any(self::LEGACY['portal_bookings']),
        ];
    }
}
