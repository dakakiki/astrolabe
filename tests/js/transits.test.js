import { describe, expect, it } from 'vitest';

import { contactLines, nearestExact, transitQuery, transitsRoute } from '@/lib/transits';

describe('transitQuery', () => {
    it('sends a chosen moment with its zone, and only the zone for now', () => {
        expect(transitQuery('2026-10-05T15:00', 'Europe/Belgrade')).toEqual({
            at: '2026-10-05T15:00',
            timezone: 'Europe/Belgrade',
        });
        expect(transitQuery('', 'Europe/Belgrade')).toEqual({ timezone: 'Europe/Belgrade' });
    });
});

describe('transitsRoute', () => {
    it('opens the transits tab at an appointment’s start on the viewer’s clock', () => {
        expect(transitsRoute(4, '2026-10-02T09:00:00Z', 'Europe/Belgrade')).toEqual({
            name: 'clients.show',
            params: { id: 4 },
            query: { tab: 'transits', at: '2026-10-02T11:00' },
        });
        expect(transitsRoute(4, '2026-10-02T09:00:00Z', 'America/New_York').query.at).toBe('2026-10-02T05:00');
        expect(transitsRoute(4, null, 'UTC').query).toEqual({ tab: 'transits' });
    });
});

describe('nearestExact', () => {
    const exact = ['2026-04-02T01:43:00Z', '2026-10-24T09:57:00Z', '2027-01-29T21:04:00Z'];

    it('prefers the next date after the moment', () => {
        expect(nearestExact(exact, '2026-09-25T08:00:00Z')).toEqual({ date: '2026-10-24T09:57:00Z', past: false });
    });

    it('falls back to the last one before it', () => {
        expect(nearestExact(exact, '2027-06-01T00:00:00Z')).toEqual({ date: '2027-01-29T21:04:00Z', past: true });
    });

    it('has nothing to say for a fast planet or when none was found', () => {
        expect(nearestExact(null, '2026-09-25T08:00:00Z')).toBeNull();
        expect(nearestExact([], '2026-09-25T08:00:00Z')).toBeNull();
    });
});

describe('contactLines', () => {
    it('joins transiting bodies to natal bodies and angles', () => {
        const lines = contactLines(
            [
                { transit: 'saturn', natal: 'sun', type: 'square' },
                { transit: 'jupiter', natal: 'mc', type: 'conjunction' },
                { transit: 'pluto', natal: 'vertex', type: 'trine' },
            ],
            [
                { body: 'saturn', longitude: 100.5 },
                { body: 'jupiter', longitude: 140 },
            ],
            { positions: [{ body: 'sun', longitude: 10 }], angles: { asc: 200, mc: 140.2 } },
        );

        expect(lines).toHaveLength(2);
        expect(lines[0]).toMatchObject({ from: 100.5, to: 10 });
        expect(lines[1]).toMatchObject({ from: 140, to: 140.2 });
    });
});
