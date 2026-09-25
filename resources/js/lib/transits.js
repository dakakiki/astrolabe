import { toLocalInput } from '@/lib/datetime';

/**
 * Transits to a natal chart (docs/spec/11, Phase 7a). The server calculates
 * the positions, the contacts and the dates slow transits are exact; this
 * builds the requests and links and picks what to show first.
 */

/** Query parameters for a moment given as wall-clock time ("2026-10-05T15:00") in a zone; none means now. */
export function transitQuery(localInput, timeZone) {
    return localInput ? { at: localInput, timezone: timeZone } : { timezone: timeZone };
}

/**
 * The link that opens a client's transits for a moment — an appointment's or
 * a consultation's start — on the viewer's own clock.
 */
export function transitsRoute(clientId, isoTimestamp, timeZone) {
    const query = { tab: 'transits' };
    if (isoTimestamp) query.at = toLocalInput(new Date(isoTimestamp), timeZone);

    return { name: 'clients.show', params: { id: clientId }, query };
}

/**
 * The exact date worth showing first: the next one from the moment, else the
 * most recent before it. Null for a fast planet (no search) or none found.
 */
export function nearestExact(exact, momentIso) {
    if (!exact?.length) return null;

    const moment = new Date(momentIso).getTime();
    const next = exact.find((date) => new Date(date).getTime() >= moment);

    return next ? { date: next, past: false } : { date: exact[exact.length - 1], past: true };
}

/**
 * Longitudes of the points the contacts join, for drawing: transiting bodies
 * from the transit positions, natal ones from the chart (bodies and angles).
 */
export function contactLines(contacts, positions, natal) {
    const transiting = Object.fromEntries(positions.map((position) => [position.body, position.longitude]));
    const natalPoints = Object.fromEntries(natal.positions.map((position) => [position.body, position.longitude]));
    Object.assign(natalPoints, natal.angles ?? {});

    return contacts
        .filter((contact) => transiting[contact.transit] !== undefined && natalPoints[contact.natal] !== undefined)
        .map((contact) => ({ ...contact, from: transiting[contact.transit], to: natalPoints[contact.natal] }));
}
