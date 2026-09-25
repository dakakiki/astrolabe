import { toLocalInput } from '@/lib/datetime';

/**
 * The sky calendar (Phase 7d): what the server found in a period — aspects
 * between the planets with their exact minute, stations, ingresses, new and
 * full moons, and retrograde arcs — filtered and laid out by day on the
 * viewer's clock. The search itself runs on the server (SkyCalendar).
 */

/** Periods offered, in days (SkyCalendar::PERIODS). */
export const SKY_PERIODS = [7, 30, 90, 365];

export const DEFAULT_PERIOD = 30;

export const EVENT_TYPES = ['aspect', 'station', 'ingress', 'lunation'];

export const SKY_ASPECTS = ['conjunction', 'sextile', 'square', 'trine', 'quincunx', 'opposition'];

/** Bodies to filter by; the Moon only takes part in new and full moons. */
export const SKY_BODIES = [
    'sun',
    'moon',
    'mercury',
    'venus',
    'mars',
    'jupiter',
    'saturn',
    'uranus',
    'neptune',
    'pluto',
    'chiron',
];

const DAY_KEY = /^\d{4}-\d{2}-\d{2}$/;

/** The page's state from the address, cleaned: unknown values fall back to all / the default. */
export function skyQuery(query) {
    const days = Number(query.days);

    return {
        from: DAY_KEY.test(query.from ?? '') ? query.from : null,
        days: SKY_PERIODS.includes(days) ? days : DEFAULT_PERIOD,
        body: SKY_BODIES.includes(query.body) ? query.body : '',
        type: EVENT_TYPES.includes(query.type) ? query.type : '',
        aspect: SKY_ASPECTS.includes(query.aspect) ? query.aspect : '',
    };
}

/** The bodies an event is about. */
export function eventBodies(event) {
    switch (event.type) {
        case 'aspect':
            return event.bodies.map((item) => item.body);
        case 'lunation':
            return ['sun', 'moon'];
        default:
            return [event.body];
    }
}

/** Events that pass the filters: a body taking part, a kind of event, one aspect (only aspects then). */
export function filterEvents(events, { body = '', type = '', aspect = '' } = {}) {
    return events.filter(
        (event) =>
            (!body || eventBodies(event).includes(body)) &&
            (!type || event.type === type) &&
            (!aspect || (event.type === 'aspect' && event.aspect === aspect)),
    );
}

/** Retrograde arcs that pass the same filters (they are always aspects). */
export function filterArcs(arcs, { body = '', type = '', aspect = '' } = {}) {
    return arcs.filter(
        (arc) =>
            (!body || arc.bodies.includes(body)) && (!type || type === 'aspect') && (!aspect || arc.aspect === aspect),
    );
}

/** Events by day on the viewer's clock, in order: [{ day: "2026-10-24", events: [...] }]. */
export function groupByDay(events, timeZone) {
    const days = [];

    for (const event of events) {
        const day = toLocalInput(new Date(event.at), timeZone || 'UTC').slice(0, 10);
        const last = days.at(-1);

        if (last?.day === day) last.events.push(event);
        else days.push({ day, events: [event] });
    }

    return days;
}

/** The hard aspects warn, the others inform — as the badge colours in the prototype. */
export function aspectTone(aspect) {
    return aspect === 'square' || aspect === 'opposition' ? 'warn' : 'info';
}
