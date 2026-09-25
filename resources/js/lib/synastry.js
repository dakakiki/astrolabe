import { normalize } from '@/lib/chart';

/**
 * Synastry and the composite chart (docs/spec/11, Phase 7e). The server
 * compares the two charts; this names who is compared in the URL, builds the
 * request, picks the contacts read first and lays the other chart out for the
 * outer ring of the wheel.
 */

/** The planets read first in a relationship, as in the prototype: Sun, Moon, Venus and Mars. */
export const PERSONAL = ['sun', 'moon', 'venus', 'mars'];

/** Who is compared, as the URL keeps it: "person-5" or "client-7". */
export function withValue(kind, id) {
    return `${kind}-${id}`;
}

/** The URL value read back as { kind, id }; null when it is not one. */
export function parseWith(value) {
    const match = /^(person|client)-(\d+)$/.exec(typeof value === 'string' ? value : '');

    return match ? { kind: match[1], id: Number(match[2]) } : null;
}

/** Query parameters for GET /clients/{id}/synastry. */
export function synastryQuery(other) {
    return other.kind === 'person' ? { with_person: other.id } : { with_client: other.id };
}

/** The link that opens a client's synastry tab with a related person or another client. */
export function synastryRoute(clientId, kind, id) {
    return { name: 'clients.show', params: { id: clientId }, query: { tab: 'synastry', with: withValue(kind, id) } };
}

/**
 * Who can be picked from a client's links (GET /clients/{id}/relationships):
 * related people and linked clients, in the links' order, each saying
 * whether it has a chart to compare.
 */
export function comparisonOptions(links) {
    return links.map((link) => ({
        value: withValue(link.kind, link.party.id),
        kind: link.kind,
        id: link.party.id,
        name: link.party.full_name,
        relationship: link.relationship_type,
        ready: Boolean(link.party.birth?.chart_ready),
    }));
}

/** The contacts between the personal planets, in the order given (closest first from the server). */
export function personalContacts(contacts) {
    return contacts.filter((contact) => PERSONAL.includes(contact.a) && PERSONAL.includes(contact.b));
}

/**
 * The other person's chart for the outer ring: the bodies, then the Ascendant
 * and Midheaven when their birth time is known. Without it the Moon sits in
 * the middle of the span it covered that day and says so (`range`).
 */
export function outerPoints(chart) {
    const positions = chart.positions.map((position) => {
        if (position.body !== 'moon' || !chart.moon_range) return position;

        const { from, to } = chart.moon_range;

        return { ...position, longitude: normalize(from + normalize(to - from) / 2), range: { from, to } };
    });
    const angles = chart.angles
        ? ['asc', 'mc'].map((key) => ({ body: key, longitude: chart.angles[key], retrograde: false }))
        : [];

    return [...positions, ...angles];
}

/** The longitudes each contact joins, for drawing: the other person's point to the client's. */
export function synastryLines(contacts, other, client) {
    const theirs = pointLongitudes(other);
    const ours = pointLongitudes(client);

    return contacts
        .filter((contact) => theirs[contact.a] !== undefined && ours[contact.b] !== undefined)
        .map((contact) => ({ ...contact, from: theirs[contact.a], to: ours[contact.b] }));
}

/**
 * One person's positions in the other's houses, shaped for PositionsTable:
 * `houses` is the server's overlay (point => house), null when the host chart
 * has none. The guest's own angles come along.
 */
export function overlayChart(guest, host, houses) {
    return {
        positions: guest.positions.map((position) => ({ ...position, house: houses?.[position.body] ?? null })),
        houses: houses ? host.houses : null,
        angles: guest.angles,
        moon_range: guest.moon_range,
    };
}

function pointLongitudes(chart) {
    const points = Object.fromEntries(chart.positions.map((position) => [position.body, position.longitude]));

    return Object.assign(points, chart.angles ?? {});
}
