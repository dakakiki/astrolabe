<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A booking step that the current state does not allow (Phase 9b), answered
 * with `{message, code}` so both SPAs can react to the code:
 *
 * - `slot_taken` (409): the time went in the meantime — with a fresh list of
 *   free `slots`, never with what took it (docs/spec/10: clients never learn why);
 * - `change_too_late` (409): past the policy's deadline for moving or cancelling;
 * - `not_changeable` (409): no longer open (held, cancelled, already decided);
 * - `not_requested` (409): the astrologer confirms or declines what is no request;
 * - `too_many_open` (409): the client already holds as many open appointments
 *   as the portal allows (`portal.booking.max_open_per_client`);
 * - `booking_disabled` (403): the practice does not take bookings in the portal now.
 */
final class BookingConflict extends RuntimeException
{
    /**
     * @param  list<array{starts_at: string, ends_at: string}>|null  $slots
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly ?array $slots = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<CarbonImmutable>  $starts
     */
    public static function slotTaken(array $starts, int $minutes): self
    {
        return new self('slot_taken', __('booking.slot_taken'), 409, self::slotList($starts, $minutes));
    }

    public static function tooLate(): self
    {
        return new self('change_too_late', __('booking.change_too_late'));
    }

    public static function notChangeable(): self
    {
        return new self('not_changeable', __('booking.not_changeable'));
    }

    /** The astrologer's confirm or decline on something that is not a waiting request. */
    public static function notRequested(): self
    {
        return new self('not_requested', __('booking.not_requested'));
    }

    /** The client holds `$max` open appointments already: the practice books any more. */
    public static function tooManyOpen(int $max): self
    {
        return new self('too_many_open', __('booking.too_many_open', ['max' => $max]));
    }

    public static function disabled(): self
    {
        return new self('booking_disabled', __('booking.disabled'), 403);
    }

    /**
     * Free start times as the API sends them: UTC, with the end.
     *
     * @param  list<CarbonImmutable>  $starts
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public static function slotList(array $starts, int $minutes): array
    {
        return array_map(fn (CarbonImmutable $start) => [
            'starts_at' => $start->toIso8601ZuluString(),
            'ends_at' => $start->addMinutes($minutes)->toIso8601ZuluString(),
        ], $starts);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'slots' => $this->slots,
        ], fn ($value) => $value !== null), $this->status);
    }
}
