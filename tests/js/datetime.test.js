import { describe, expect, it } from 'vitest';

import { formatDateTime, isFuture, localInputNow, toLocalInput } from '../../resources/js/lib/datetime';

describe('toLocalInput', () => {
    it('shows a UTC moment on the wall clock of a zone, with its summer time', () => {
        const moment = new Date('2026-07-15T12:30:00Z');

        expect(toLocalInput(moment, 'Europe/Belgrade')).toBe('2026-07-15T14:30');
        expect(toLocalInput(moment, 'America/New_York')).toBe('2026-07-15T08:30');
        expect(toLocalInput(new Date('2026-01-15T12:30:00Z'), 'Europe/Belgrade')).toBe('2026-01-15T13:30');
    });

    it('moves to the next day where the zone is already past midnight', () => {
        expect(toLocalInput(new Date('2026-09-24T23:30:00Z'), 'Asia/Tokyo')).toBe('2026-09-25T08:30');
    });

    it('uses UTC when no zone is known', () => {
        expect(toLocalInput(new Date('2026-09-24T23:30:00Z'), null)).toBe('2026-09-24T23:30');
    });
});

describe('localInputNow', () => {
    it('rounds down to the quarter hour', () => {
        expect(localInputNow('Europe/Belgrade', new Date('2026-09-24T13:44:59Z'))).toBe('2026-09-24T15:30');
        expect(localInputNow('Europe/Belgrade', new Date('2026-09-24T13:00:00Z'))).toBe('2026-09-24T15:00');
    });
});

describe('formatDateTime', () => {
    it('formats in the requested zone', () => {
        expect(formatDateTime('2026-07-15T12:30:00Z', 'en-GB', 'Europe/Belgrade')).toBe('15 Jul 2026, 14:30');
    });

    it('can show only the date or only the time', () => {
        const moment = '2026-07-15T22:30:00Z';

        // Already the next day in Belgrade.
        expect(formatDateTime(moment, 'en-GB', 'Europe/Belgrade', { dateStyle: 'medium' })).toBe('16 Jul 2026');
        expect(formatDateTime(moment, 'en-GB', 'Europe/Belgrade', { timeStyle: 'short' })).toBe('00:30');
    });

    it('is empty without a value', () => {
        expect(formatDateTime(null, 'en-GB', 'UTC')).toBe('');
    });
});

describe('isFuture', () => {
    it('compares with now', () => {
        const now = new Date('2026-09-24T12:00:00Z');

        expect(isFuture('2026-09-24T12:00:01Z', now)).toBe(true);
        expect(isFuture('2026-09-24T11:59:59Z', now)).toBe(false);
        expect(isFuture(null, now)).toBe(false);
    });
});
