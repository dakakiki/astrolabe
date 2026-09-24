/**
 * Moments (UTC timestamps from the API) shown on the wall clock of a time zone —
 * usually the astrologer's own, as in the top bar.
 */

function zonedParts(date, timeZone) {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);

    return Object.fromEntries(parts.filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]));
}

/**
 * The wall-clock time in a zone as a datetime-local value ("2026-09-24T15:00"),
 * which is what the API takes together with the zone.
 */
export function toLocalInput(date, timeZone) {
    const parts = zonedParts(date, timeZone || 'UTC');

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

/** The current time in a zone, rounded down to the quarter hour, for a new record. */
export function localInputNow(timeZone, now = new Date()) {
    const value = toLocalInput(now, timeZone);
    const minutes = Math.floor(Number(value.slice(14, 16)) / 15) * 15;

    return `${value.slice(0, 14)}${String(minutes).padStart(2, '0')}`;
}

/** "24 Sept 2026, 15:00" in the given zone; pass { dateStyle } or { timeStyle } alone for one half. */
export function formatDateTime(isoTimestamp, locale, timeZone, styles = { dateStyle: 'medium', timeStyle: 'short' }) {
    if (!isoTimestamp) return '';
    const date = new Date(isoTimestamp);

    try {
        return new Intl.DateTimeFormat(locale, { ...styles, timeZone }).format(date);
    } catch {
        return new Intl.DateTimeFormat(locale, styles).format(date);
    }
}

export function isFuture(isoTimestamp, now = new Date()) {
    return Boolean(isoTimestamp) && new Date(isoTimestamp).getTime() > now.getTime();
}
