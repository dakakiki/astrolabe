import { toLocalInput } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';

/**
 * Pure helpers of booking in the client portal (docs/spec/12, "Zakazivanje
 * (9b)"): free times grouped by day on the client's clock, how a day, a time
 * and a price read, which change rules apply, and what an answer from the
 * server means for the screen. Covered by Vitest; nothing here touches the
 * DOM or the network.
 */

const DAY = 86_400_000;
const MOMENT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/;

/** A moment written as the server writes one (Z or an offset) that names a real time — "2026-13-01T10:00Z" does not. */
function isMoment(value) {
    return MOMENT.test(value) && !Number.isNaN(Date.parse(value));
}

/** Two spellings of the same moment ("…T09:00:00Z" and "…T10:00:00+01:00"); never two unreadable ones. */
export function sameMoment(first, second) {
    const a = Date.parse(first);

    return !Number.isNaN(a) && a === Date.parse(second);
}

/**
 * A fresh Idempotency-Key for one booking attempt: a retry of the same
 * confirmation sends it again, so the server books once. The portal's own
 * copy — the application's lib/http would bring its API client along.
 */
export function idempotencyKey(prefix = 'booking') {
    const bytes = crypto.getRandomValues(new Uint8Array(16));

    return `${prefix}-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * The wall-clock day ("2026-10-25") of a moment in a zone; UTC when the zone
 * is unknown, null when the moment is not a real one.
 */
export function dayOf(isoTimestamp, timeZone) {
    // Nothing is no moment either (Date would read null as 1 January 1970).
    const date = new Date(isoTimestamp ?? Number.NaN);
    if (Number.isNaN(date.getTime())) return null;

    try {
        return toLocalInput(date, timeZone).slice(0, 10);
    } catch {
        return toLocalInput(date, 'UTC').slice(0, 10);
    }
}

/**
 * Free times by day on the client's clock: [{ date: "2026-10-26", slots }],
 * days and times in order. A day is the zone's own, so a time at 00:30 after
 * a change to winter time still lands on the right date.
 */
export function groupSlotsByDay(slots, timeZone) {
    const days = new Map();
    const ordered = [...(slots ?? [])].sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at));

    for (const slot of ordered) {
        const date = dayOf(slot.starts_at, timeZone);

        if (!days.has(date)) days.set(date, []);
        days.get(date).push(slot);
    }

    return [...days].map(([date, daySlots]) => ({ date, slots: daySlots }));
}

/**
 * The day to show after the list changed (a refresh after "that time was just
 * taken"): the same day while it still has times, else the next one that has,
 * else the last; null when nothing is free.
 */
export function nextDay(groups, previous = null) {
    if (!groups?.length) return null;
    if (!previous) return groups[0].date;

    return (groups.find((group) => group.date >= previous) ?? groups[groups.length - 1]).date;
}

/**
 * A day key in parts for the day strip — "Mon", "26", "Oct" — and in full,
 * "Monday 26 October". Formatted at noon UTC, so no zone can move the date.
 */
export function dayLabel(date, locale) {
    const noon = new Date(`${date}T12:00:00Z`);
    const part = (options) => new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(noon);

    return {
        weekday: part({ weekday: 'short' }),
        day: part({ day: 'numeric' }),
        month: part({ month: 'short' }),
        full: part({ weekday: 'long', day: 'numeric', month: 'long' }),
    };
}

/** "10:00" (or "10:00 AM") on the wall clock of `timeZone`; empty when the moment is not a real one. */
export function slotTime(isoTimestamp, locale, timeZone) {
    const date = new Date(isoTimestamp ?? Number.NaN);
    if (Number.isNaN(date.getTime())) return '';

    try {
        return new Intl.DateTimeFormat(locale, { timeStyle: 'short', timeZone }).format(date);
    } catch {
        return new Intl.DateTimeFormat(locale, { timeStyle: 'short', timeZone: 'UTC' }).format(date);
    }
}

/** The zone's short name at a moment — "EDT", "BST", "GMT+2" — or empty when it cannot be told. */
export function zoneName(isoTimestamp, locale, timeZone) {
    const date = new Date(isoTimestamp ?? Number.NaN);
    if (Number.isNaN(date.getTime())) return '';

    try {
        return (
            new Intl.DateTimeFormat(locale, { timeZone, timeZoneName: 'short' })
                .formatToParts(date)
                .find((part) => part.type === 'timeZoneName')?.value ?? ''
        );
    } catch {
        return '';
    }
}

/**
 * The labels of one day's free times, in the same order, as { time, zone }.
 * On the night the clocks go back an hour happens twice ("1:30 AM" in summer
 * time, then again in winter time), so a time that reads like another one
 * carries its zone's short name — "EDT", then "EST"; every other time has
 * none (an empty zone).
 */
export function slotLabels(slots, locale, timeZone) {
    const times = (slots ?? []).map((slot) => slotTime(slot.starts_at, locale, timeZone));

    return times.map((time, index) => ({
        time,
        zone: times.indexOf(time) === times.lastIndexOf(time) ? '' : zoneName(slots[index].starts_at, locale, timeZone),
    }));
}

/**
 * The moment whose zone names a day's times: the chosen time when it is on
 * that day (on the night the clocks go back the zone changes within the day),
 * else the day's first time; null for a day without times.
 */
export function dayZoneMoment(slots, selected = null) {
    const list = slots ?? [];
    const chosen = selected ? list.find((slot) => sameMoment(slot.starts_at, selected)) : null;

    return (chosen ?? list[0])?.starts_at ?? null;
}

/** "10:00 – 11:30": a free time's start and end on the client's clock. */
export function slotRange(slot, locale, timeZone) {
    return `${slotTime(slot.starts_at, locale, timeZone)} – ${slotTime(slot.ends_at, locale, timeZone)}`;
}

/** The free time that starts at `isoTimestamp`, or null — compared as moments, not as text. */
export function findSlot(slots, isoTimestamp) {
    const moment = Date.parse(isoTimestamp);
    if (!isoTimestamp || Number.isNaN(moment)) return null;

    return (slots ?? []).find((slot) => Date.parse(slot.starts_at) === moment) ?? null;
}

/**
 * The free times without the one starting at `isoTimestamp` — on the
 * reschedule screen the appointment's own time, which would change nothing.
 * The server leaves it out already; this keeps an older list honest too.
 */
export function withoutMoment(slots, isoTimestamp) {
    if (!slots) return slots;

    return slots.filter((slot) => !sameMoment(slot.starts_at, isoTimestamp));
}

/** "€90" for a service's price, shown for information only; null when it has none. */
export function priceText(price, locale) {
    if (!price) return null;

    return formatMoney(price, locale, price.decimals ?? 2);
}

/** How many days ahead the list reaches (for "No free times in the next N days"), at least one. */
export function daysAhead(isoTimestamp, now = new Date()) {
    const until = Date.parse(isoTimestamp);
    if (Number.isNaN(until)) return 1;

    return Math.max(1, Math.round((until - now.getTime()) / DAY));
}

/**
 * The practice's change rules as lines to show before booking:
 * `portal.booking.rules.<key>` with `count` hours. Null hours mean the client
 * may not do that in the portal.
 */
export function changeRules(rescheduleHours, cancelHours) {
    const reschedule = rescheduleHours ?? null;
    const cancel = cancelHours ?? null;

    if (reschedule === null && cancel === null) return [{ key: 'none' }];
    if (reschedule === cancel) return [{ key: 'both', count: reschedule }];

    return [
        reschedule === null ? { key: 'rescheduleNone' } : { key: 'reschedule', count: reschedule },
        cancel === null ? { key: 'cancelNone' } : { key: 'cancel', count: cancel },
    ];
}

/**
 * Until when an upcoming appointment can still be changed here, as lines:
 * `portal.appointments.until.<key>` with the moment `at`. Empty when it can
 * no longer be changed.
 */
export function changeDeadlines(appointment) {
    const reschedule = appointment?.can_reschedule ? appointment.reschedule_until : null;
    const cancel = appointment?.can_cancel ? appointment.cancel_until : null;

    if (reschedule && cancel && Date.parse(reschedule) === Date.parse(cancel)) {
        return [{ key: 'both', at: reschedule }];
    }

    return [
        ...(reschedule ? [{ key: 'reschedule', at: reschedule }] : []),
        ...(cancel ? [{ key: 'cancel', at: cancel }] : []),
    ];
}

/** What the client can do with an upcoming appointment here; `contact` when nothing. */
export function changeOptions(appointment) {
    const reschedule = Boolean(appointment?.upcoming && appointment.can_reschedule);
    const cancel = Boolean(appointment?.upcoming && appointment.can_cancel);

    return { reschedule, cancel, contact: Boolean(appointment?.upcoming) && !reschedule && !cancel };
}

/**
 * The badge an appointment carries: `portal.appointments.status.<key>` and
 * its tone, or null for a confirmed appointment that is still ahead.
 */
export function statusBadge(appointment) {
    const { status, upcoming } = appointment;

    // A request is open until the practice answers; one whose time passed was never confirmed.
    if (status === 'requested') return upcoming ? { key: 'requested', tone: 'b-warn' } : { key: 'declined', tone: '' };
    if (upcoming) return null;

    if (status === 'cancelled') {
        if (appointment.declined) return { key: 'declined', tone: '' };

        return { key: appointment.cancelled_by === 'client' ? 'cancelled_by_client' : 'cancelled', tone: '' };
    }

    return { key: status, tone: { completed: 'b-ok', no_show: 'b-warn', scheduled: 'b-info' }[status] ?? '' };
}

/**
 * The booking screen's place in the address: `?service=ID` is the second
 * step, `&at=<UTC moment>` the third. Anything else reads as not chosen.
 */
export function readBookingQuery(query) {
    const service = /^\d{1,18}$/.test(String(query?.service ?? '')) ? Number(query.service) : null;
    const at = service !== null && isMoment(String(query?.at ?? '')) ? String(query.at) : null;

    return { serviceId: service, at };
}

/** The confirmed time from the address of the reschedule screen, or null. */
export function readMoment(value) {
    return isMoment(String(value ?? '')) ? String(value) : null;
}

/**
 * The body of POST /appointments: the place only for a service held either
 * way, the message only when something was written.
 */
export function bookingPayload({ service, startsAt, location = null, message = '' }) {
    const payload = { service_id: service.id, starts_at: startsAt };
    const text = String(message ?? '').trim();

    if (service.location_type === 'either' && location) payload.location_type = location;
    if (text) payload.message = text;

    return payload;
}

/**
 * What a failed booking request means for the screen: `slot_taken` (with the
 * fresh list when the server sent one), `change_too_late`, `not_changeable`,
 * `too_many_open` (the client already holds as many upcoming appointments as
 * the portal books for one person), `booking_disabled`, `not_found`,
 * `invalid` (422), `too_many` (429), `handled` (signed out or the practice
 * closed — the portal has moved on) or `generic`.
 *
 * `invalid` tells with `fields` whether the server named any field. A 422
 * naming none comes from the Idempotency-Key check: this key already went
 * through once (its answer was lost on the way) and the details were changed
 * since — so what was asked may well have happened.
 */
export function bookingProblem(error) {
    const status = error?.response?.status;
    const data = error?.response?.data ?? {};

    if (status === 409 && data.code === 'slot_taken') {
        return { code: 'slot_taken', slots: Array.isArray(data.slots) ? data.slots : null };
    }
    if (status === 409 && ['change_too_late', 'not_changeable', 'too_many_open'].includes(data.code)) {
        return { code: data.code };
    }
    if (status === 403 && data.code === 'booking_disabled') return { code: 'booking_disabled' };
    if (status === 401 || status === 403) return { code: 'handled' };
    if (status === 404) return { code: 'not_found' };
    if (status === 422) return { code: 'invalid', fields: Object.keys(data.errors ?? {}).length > 0 };
    if (status === 429) return { code: 'too_many' };

    return { code: 'generic' };
}
