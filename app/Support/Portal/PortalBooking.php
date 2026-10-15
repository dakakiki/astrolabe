<?php

namespace App\Support\Portal;

use App\Models\BookingPolicy;
use App\Models\Service;
use App\Models\User;
use App\Support\Booking\BookingAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Booking in the portal as one request sees it (docs/spec/12, "Zakazivanje
 * (9b)"): the practice's booking policy and the astrologer bookings go to,
 * read once per request and kept on it — a list of appointments asks for them
 * once, not once per appointment — and the services a client may book.
 *
 * Runs inside the tenant scope `portal.access` sets.
 */
final class PortalBooking
{
    private const POLICY = 'portal.booking.policy';

    private const ASTROLOGER = 'portal.booking.astrologer';

    public static function policy(Request $request): BookingPolicy
    {
        if (! $request->attributes->has(self::POLICY)) {
            $request->attributes->set(self::POLICY, BookingPolicy::current());
        }

        return $request->attributes->get(self::POLICY);
    }

    /** The practice's booking astrologer (its owner until teams), or null when it has none. */
    public static function astrologer(Request $request): ?User
    {
        if (! $request->attributes->has(self::ASTROLOGER)) {
            $request->attributes->set(self::ASTROLOGER, self::policy($request)->astrologer());
        }

        return $request->attributes->get(self::ASTROLOGER);
    }

    public static function astrologerId(Request $request): ?int
    {
        return self::astrologer($request)?->getKey();
    }

    /** Whether clients can book now: the practice switched it on and has someone to book with. */
    public static function open(Request $request): bool
    {
        return self::policy($request)->enabled && self::astrologer($request) !== null;
    }

    /** The booking astrologer's free times; null while the practice takes no bookings. */
    public static function availability(Request $request): ?BookingAvailability
    {
        return self::open($request) ? new BookingAvailability(self::policy($request), self::astrologer($request)) : null;
    }

    /**
     * What a client may book: active services the astrologer switched on for the
     * portal. Anything else — inactive, not bookable, another practice's — is not
     * found (404), whatever its id.
     *
     * @return Builder<Service>
     */
    public static function services(): Builder
    {
        return Service::query()->where('is_active', true)->where('bookable_in_portal', true);
    }
}
