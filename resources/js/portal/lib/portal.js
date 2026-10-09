/**
 * Pure helpers of the client portal (docs/spec/12): tokens from email links,
 * the time zone times are shown in, and how an appointment's time reads.
 * Covered by Vitest.
 */

/**
 * The token an email link carries after "#" (it never reaches a server log), or
 * null when there is none or it does not look like one of ours.
 */
export function readToken(hash) {
    const token = String(hash ?? '').replace(/^#/, '');

    return /^[A-Za-z0-9]{20,128}$/.test(token) ? token : null;
}

/** The client's own zone once chosen; until then the practice's. */
export function displayZone(user, practice) {
    return user?.timezone || practice?.timezone || 'UTC';
}

/**
 * Offer the device's zone when the person has not chosen one and the device
 * is somewhere else than the practice; null otherwise.
 */
export function suggestedZone(user, practice, deviceZone) {
    if (user?.timezone || !deviceZone || !practice?.timezone) return null;

    return deviceZone === practice.timezone ? null : deviceZone;
}

/** "London (BST)" — the place of the zone and its short name at that moment. */
export function zoneLabel(timeZone, isoTimestamp, locale = 'en') {
    const place = String(timeZone).split('/').pop().replaceAll('_', ' ');
    let short = '';

    try {
        short =
            new Intl.DateTimeFormat(locale, { timeZone, timeZoneName: 'short' })
                .formatToParts(isoTimestamp ? new Date(isoTimestamp) : new Date())
                .find((part) => part.type === 'timeZoneName')?.value ?? '';
    } catch {
        return place;
    }

    return short && short !== place ? `${place} (${short})` : place;
}

/** An appointment's day and its hours, on the wall clock of `timeZone`. */
export function appointmentWhen(appointment, locale, timeZone) {
    const options = { timeZone };
    const start = new Date(appointment.starts_at);
    const end = new Date(appointment.ends_at);

    try {
        const date = new Intl.DateTimeFormat(locale, { ...options, dateStyle: 'full' }).format(start);
        const time = new Intl.DateTimeFormat(locale, { ...options, timeStyle: 'short' });

        return { date, time: `${time.format(start)} – ${time.format(end)}` };
    } catch {
        return { date: start.toDateString(), time: '' };
    }
}

/** Whether a location detail is a link to open (an online meeting) rather than a place. */
export function isLink(value) {
    return /^https?:\/\/\S+$/i.test(String(value ?? '').trim());
}
