<?php

namespace App\Support\Notifications;

/**
 * What email a person wants and when (Settings → Notifications). Stored on the
 * user (`users.notification_preferences`) and laid over the defaults, so a
 * setting added later starts from its default instead of breaking old rows.
 * Times are wall-clock times in the user's own zone.
 *
 * `portal_bookings` (Phase 9b, docs/spec/12): an email when a client books,
 * moves or cancels in the client portal, or asks for a time to be confirmed —
 * on unless switched off, and held back by quiet hours like everything else.
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

    public function __construct(
        public bool $appointmentReminders,
        public int $reminderMinutes,
        public bool $taskDigest,
        public string $digestTime,
        public bool $portalBookings,
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

        return new self(
            appointmentReminders: (bool) ($stored['appointment_reminders'] ?? true),
            reminderMinutes: (int) ($stored['reminder_minutes'] ?? self::DEFAULT_REMINDER_MINUTES),
            taskDigest: (bool) ($stored['task_digest'] ?? true),
            digestTime: (string) ($stored['digest_time'] ?? self::DEFAULT_DIGEST_TIME),
            portalBookings: (bool) ($stored['portal_bookings'] ?? true),
            quietHours: is_array($quiet) ? new QuietHours((string) $quiet['start'], (string) $quiet['end']) : null,
        );
    }

    /**
     * @return array{appointment_reminders: bool, reminder_minutes: int, task_digest: bool, digest_time: string, portal_bookings: bool, quiet_hours: array{start: string, end: string}|null}
     */
    public function toArray(): array
    {
        return [
            'appointment_reminders' => $this->appointmentReminders,
            'reminder_minutes' => $this->reminderMinutes,
            'task_digest' => $this->taskDigest,
            'digest_time' => $this->digestTime,
            'portal_bookings' => $this->portalBookings,
            'quiet_hours' => $this->quietHours === null ? null : [
                'start' => $this->quietHours->start,
                'end' => $this->quietHours->end,
            ],
        ];
    }
}
