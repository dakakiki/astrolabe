import { describe, expect, it } from 'vitest';

import {
    comparisonOptions,
    outerPoints,
    overlayChart,
    parseWith,
    personalContacts,
    synastryLines,
    synastryQuery,
    synastryRoute,
    withValue,
} from '@/lib/synastry';

describe('who is compared, in the URL and the request', () => {
    it('writes and reads back a person or a client', () => {
        expect(withValue('person', 5)).toBe('person-5');
        expect(parseWith('person-5')).toEqual({ kind: 'person', id: 5 });
        expect(parseWith('client-17')).toEqual({ kind: 'client', id: 17 });
    });

    it('ignores anything else', () => {
        for (const value of ['', 'person-', 'people-5', 'client-5x', undefined, null, ['client-5']]) {
            expect(parseWith(value)).toBeNull();
        }
    });

    it('asks the server for a related person or another client', () => {
        expect(synastryQuery({ kind: 'person', id: 5 })).toEqual({ with_person: 5 });
        expect(synastryQuery({ kind: 'client', id: 7 })).toEqual({ with_client: 7 });
    });

    it('links to the client’s synastry tab', () => {
        expect(synastryRoute(3, 'person', 5)).toEqual({
            name: 'clients.show',
            params: { id: 3 },
            query: { tab: 'synastry', with: 'person-5' },
        });
    });
});

describe('comparisonOptions', () => {
    it('turns the client’s links into choices, those without a chart marked', () => {
        const links = [
            {
                kind: 'person',
                relationship_type: 'partner',
                party: { id: 1, full_name: 'Marko Petrović', birth: { chart_ready: true } },
            },
            { kind: 'client', relationship_type: 'sibling', party: { id: 4, full_name: 'Luka Test', birth: null } },
        ];

        expect(comparisonOptions(links)).toEqual([
            { value: 'person-1', kind: 'person', id: 1, name: 'Marko Petrović', relationship: 'partner', ready: true },
            { value: 'client-4', kind: 'client', id: 4, name: 'Luka Test', relationship: 'sibling', ready: false },
        ]);
    });
});

describe('personalContacts', () => {
    it('keeps the Sun, Moon, Venus and Mars with each other, in order', () => {
        const contacts = [
            { a: 'venus', b: 'mars', type: 'trine', orb: 0.2 },
            { a: 'saturn', b: 'moon', type: 'square', orb: 0.4 },
            { a: 'moon', b: 'sun', type: 'conjunction', orb: 1.1 },
            { a: 'asc', b: 'venus', type: 'conjunction', orb: 1.5 },
        ];

        expect(personalContacts(contacts).map((contact) => `${contact.a}-${contact.b}`)).toEqual([
            'venus-mars',
            'moon-sun',
        ]);
    });
});

const client = {
    positions: [
        { body: 'sun', longitude: 112.5, retrograde: false },
        { body: 'mars', longitude: 220.5, retrograde: false },
    ],
    angles: { asc: 194.5, mc: 114.5, dsc: 14.5, ic: 294.5 },
    houses: { system: 'whole_sign', cusps: [180, 210, 240, 270, 300, 330, 0, 30, 60, 90, 120, 150] },
    moon_range: null,
};

describe('outerPoints', () => {
    it('puts the other person’s angles after their bodies', () => {
        const points = outerPoints(client);

        expect(points.map((point) => point.body)).toEqual(['sun', 'mars', 'asc', 'mc']);
        expect(points[2]).toEqual({ body: 'asc', longitude: 194.5, retrograde: false });
    });

    it('without a birth time, sets the Moon in the middle of its span, across 0° Aries too', () => {
        const unknown = {
            positions: [{ body: 'moon', longitude: 356, retrograde: false }],
            angles: null,
            moon_range: { from: 352, to: 6 },
        };

        expect(outerPoints(unknown)).toEqual([
            { body: 'moon', longitude: 359, retrograde: false, range: { from: 352, to: 6 } },
        ]);
    });
});

describe('synastryLines', () => {
    it('joins the other person’s point to the client’s', () => {
        const other = { positions: [{ body: 'venus', longitude: 100 }], angles: { asc: 12, mc: 280 } };
        const contacts = [
            { a: 'venus', b: 'mars', type: 'trine', orb: 0.5 },
            { a: 'asc', b: 'asc', type: 'conjunction', orb: 182.5 },
            { a: 'pluto', b: 'sun', type: 'square', orb: 1 },
        ];

        expect(synastryLines(contacts, other, client)).toEqual([
            { a: 'venus', b: 'mars', type: 'trine', orb: 0.5, from: 100, to: 220.5 },
            { a: 'asc', b: 'asc', type: 'conjunction', orb: 182.5, from: 12, to: 194.5 },
        ]);
    });
});

describe('overlayChart', () => {
    it('gives each position the host’s house from the server', () => {
        const chart = overlayChart(client, client, { sun: 11, mars: 2 });

        expect(chart.positions.map((position) => position.house)).toEqual([11, 2]);
        expect(chart.houses).toBe(client.houses);
        expect(chart.angles).toBe(client.angles);
    });

    it('has no houses when the host has none', () => {
        const chart = overlayChart(client, { ...client, houses: null }, null);

        expect(chart.houses).toBeNull();
        expect(chart.positions.every((position) => position.house === null)).toBe(true);
    });
});
