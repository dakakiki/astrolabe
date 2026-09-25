import { describe, expect, it } from 'vitest';

import { aspectTone, eventBodies, filterArcs, filterEvents, groupByDay, skyQuery } from '@/lib/sky';

const aspect = (a, b, type, at = '2026-10-20T06:56:00Z') => ({
    type: 'aspect',
    at,
    aspect: type,
    bodies: [
        { body: a, longitude: 213.07, retrograde: true },
        { body: b, longitude: 303.07, retrograde: false },
    ],
});
const events = [
    aspect('venus', 'pluto', 'square'),
    { type: 'ingress', at: '2026-10-23T09:38:00Z', body: 'sun', sign: 'scorpio', retrograde: false },
    { type: 'station', at: '2026-10-24T07:13:00Z', body: 'mercury', direction: 'retrograde', longitude: 230.98 },
    { type: 'lunation', at: '2026-10-26T04:12:00Z', phase: 'full', longitude: 32.76 },
    aspect('sun', 'pluto', 'square', '2026-10-26T23:30:00Z'),
];

describe('skyQuery', () => {
    it('keeps what it knows and falls back for the rest', () => {
        expect(skyQuery({ from: '2026-10-18', days: '90', body: 'mars', type: 'station', aspect: 'trine' })).toEqual({
            from: '2026-10-18',
            days: 90,
            body: 'mars',
            type: 'station',
            aspect: 'trine',
        });
        expect(skyQuery({ from: '18.10.2026', days: '8', body: 'ceres', type: 'eclipse', aspect: 'novile' })).toEqual({
            from: null,
            days: 30,
            body: '',
            type: '',
            aspect: '',
        });
    });
});

describe('filterEvents', () => {
    it('finds the events a body takes part in, the Moon only in lunations', () => {
        expect(eventBodies(events[0])).toEqual(['venus', 'pluto']);
        expect(filterEvents(events, { body: 'pluto' })).toHaveLength(2);
        expect(filterEvents(events, { body: 'moon' }).map((event) => event.type)).toEqual(['lunation']);
        expect(filterEvents(events, { body: 'sun' }).map((event) => event.type)).toEqual([
            'ingress',
            'lunation',
            'aspect',
        ]);
    });

    it('narrows by kind and by aspect', () => {
        expect(filterEvents(events, { type: 'station' })).toEqual([events[2]]);
        expect(filterEvents(events, { aspect: 'square', body: 'sun' })).toEqual([events[4]]);
        expect(filterEvents(events, { aspect: 'trine' })).toEqual([]);
        expect(filterEvents(events)).toHaveLength(5);
    });

    it('filters retrograde arcs the same way', () => {
        const arcs = [
            { bodies: ['mars', 'saturn'], aspect: 'quincunx', passes: [] },
            { bodies: ['venus', 'pluto'], aspect: 'square', passes: [] },
        ];

        expect(filterArcs(arcs, { body: 'pluto' })).toEqual([arcs[1]]);
        expect(filterArcs(arcs, { type: 'ingress' })).toEqual([]);
        expect(filterArcs(arcs, { aspect: 'quincunx', type: 'aspect' })).toEqual([arcs[0]]);
    });
});

describe('groupByDay', () => {
    it('groups by the day on the viewer’s clock, which may not be the UTC day', () => {
        const days = groupByDay(events, 'Europe/Belgrade');

        expect(days.map((day) => day.day)).toEqual(['2026-10-20', '2026-10-23', '2026-10-24', '2026-10-26', '2026-10-27']);
        expect(groupByDay(events, 'UTC').map((day) => [day.day, day.events.length])).toEqual([
            ['2026-10-20', 1],
            ['2026-10-23', 1],
            ['2026-10-24', 1],
            ['2026-10-26', 2],
        ]);
    });
});

describe('aspectTone', () => {
    it('warns for the hard aspects', () => {
        expect(aspectTone('square')).toBe('warn');
        expect(aspectTone('opposition')).toBe('warn');
        expect(aspectTone('trine')).toBe('info');
    });
});
