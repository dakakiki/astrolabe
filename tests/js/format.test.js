import { describe, expect, it } from 'vitest';

import { formatCoordinate, formatDate, formatRelative, formatTime, initials } from '../../resources/js/lib/format';

describe('formatCoordinate', () => {
    it('writes degrees and minutes with the hemisphere', () => {
        expect(formatCoordinate(45.25167, 'lat')).toBe('45°15′N');
        expect(formatCoordinate(19.83694, 'lng')).toBe('19°50′E');
        expect(formatCoordinate(-33.9249, 'lat')).toBe('33°55′S');
        expect(formatCoordinate(-118.2437, 'lng')).toBe('118°15′W');
    });

    it('carries 60 minutes over to the next degree', () => {
        expect(formatCoordinate(44.9999, 'lat')).toBe('45°00′N');
    });

    it('is empty without a value', () => {
        expect(formatCoordinate(null, 'lat')).toBe('');
    });
});

describe('formatTime', () => {
    it('shows a wall-clock time in the viewer’s language, whatever the browser’s zone', () => {
        expect(formatTime('18:00', 'en-US')).toBe('6:00 PM');
        expect(formatTime('09:05', 'en-GB')).toBe('09:05');
        expect(formatTime(null, 'en-US')).toBe('');
    });
});

describe('formatDate', () => {
    it('never shifts a birth date across a day boundary', () => {
        expect(formatDate('1985-07-15', 'en-GB')).toBe('15 July 1985');
        expect(formatDate('1985-01-01', 'en-US', 'medium')).toBe('Jan 1, 1985');
    });
});

describe('formatRelative', () => {
    it('describes a past moment', () => {
        const now = new Date('2026-09-24T12:00:00Z');
        expect(formatRelative('2026-09-21T12:00:00Z', 'en', now)).toBe('3 days ago');
        expect(formatRelative('2026-09-24T11:59:50Z', 'en', now)).toBe('this minute');
    });
});

describe('initials', () => {
    it('takes the first letters of up to two names', () => {
        expect(initials('Ana Marković Jović')).toBe('AM');
        expect(initials('šaša')).toBe('Š');
        expect(initials(null)).toBe('');
    });
});
