<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How clients book in the portal (docs/spec/12, "Zakazivanje (9b)"), one row per
 * practice — Settings → Booking. Until the owner saves it the defaults apply
 * (`current()` returns an unsaved row), and booking stays off.
 *
 * - A slot starts at least `min_notice_minutes` from now and at most
 *   `max_days_ahead` days ahead, on a `slot_step_minutes` grid from the start of
 *   each stretch of working time.
 * - Around every appointment the practice keeps `buffer_before_minutes` and
 *   `buffer_after_minutes` free (so two appointments are at least after + before apart).
 * - With `requires_confirmation` a booking waits as `requested` for the astrologer.
 * - The client may move or cancel until `client_*_until_hours` before the start
 *   (null = not in the portal at all) while booking is on.
 *
 * Bookings go to the practice's owner until teams arrive (choosing an astrologer is later).
 *
 * @property bool $enabled
 * @property int $min_notice_minutes
 * @property int $max_days_ahead
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $slot_step_minutes
 * @property bool $requires_confirmation
 * @property int|null $client_reschedule_until_hours
 * @property int|null $client_cancel_until_hours
 * @property string|null $client_note
 */
#[Fillable([
    'enabled', 'min_notice_minutes', 'max_days_ahead', 'buffer_before_minutes', 'buffer_after_minutes',
    'slot_step_minutes', 'requires_confirmation', 'client_reschedule_until_hours', 'client_cancel_until_hours',
    'client_note',
])]
class BookingPolicy extends Model
{
    use BelongsToWorkspace;

    /** The values a practice starts with (docs/spec/12). */
    public const DEFAULTS = [
        'enabled' => false,
        'min_notice_minutes' => 1440,
        'max_days_ahead' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
        'slot_step_minutes' => 30,
        'requires_confirmation' => false,
        'client_reschedule_until_hours' => 24,
        'client_cancel_until_hours' => 24,
        'client_note' => null,
    ];

    /** @var array<string, mixed> */
    protected $attributes = self::DEFAULTS;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'min_notice_minutes' => 'integer',
            'max_days_ahead' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'slot_step_minutes' => 'integer',
            'requires_confirmation' => 'boolean',
            'client_reschedule_until_hours' => 'integer',
            'client_cancel_until_hours' => 'integer',
        ];
    }

    /** The current practice's policy, or the defaults while it has none (not saved). */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    /**
     * Who portal bookings go to: the practice's owner (one astrologer until teams).
     * A suspended owner cannot sign in to see or answer a booking, so while the
     * account is suspended there is no one to book with and booking reads as off
     * (no free times, 403 `booking_disabled`); lifting the suspension opens it again.
     */
    public function astrologer(): ?User
    {
        $workspaceId = $this->workspace_id ?? app(CurrentWorkspace::class)->id();

        if ($workspaceId === null) {
            return null;
        }

        return User::query()
            ->whereNull('suspended_at')
            ->whereHas('workspaces', fn ($query) => $query
                ->whereKey($workspaceId)
                ->where('workspace_user.role', WorkspaceRole::Owner->value)
                ->where('workspace_user.status', MembershipStatus::Active->value))
            ->orderBy('id')
            ->first();
    }

    /** The first moment a new booking may start. */
    public function earliestStart(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->utc()->addMinutes($this->min_notice_minutes);
    }

    /** The last moment a new booking may start. */
    public function latestStart(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->utc()->addDays($this->max_days_ahead);
    }

    /** Until when the client may move this appointment in the portal; null = not at all. */
    public function rescheduleDeadline(Appointment $appointment): ?CarbonImmutable
    {
        return $this->deadline($appointment, $this->client_reschedule_until_hours);
    }

    /** Until when the client may cancel this appointment in the portal; null = not at all. */
    public function cancelDeadline(Appointment $appointment): ?CarbonImmutable
    {
        return $this->deadline($appointment, $this->client_cancel_until_hours);
    }

    public function clientMayReschedule(Appointment $appointment, CarbonInterface $now): bool
    {
        $deadline = $this->rescheduleDeadline($appointment);

        return $deadline !== null && $now < $deadline;
    }

    public function clientMayCancel(Appointment $appointment, CarbonInterface $now): bool
    {
        $deadline = $this->cancelDeadline($appointment);

        return $deadline !== null && $now < $deadline;
    }

    /** Changes in the portal need booking on, an open appointment and the rule's hours. */
    private function deadline(Appointment $appointment, ?int $hours): ?CarbonImmutable
    {
        if (! $this->enabled || $hours === null || ! $appointment->status->isOpen()) {
            return null;
        }

        return $appointment->starts_at->subHours($hours);
    }
}
