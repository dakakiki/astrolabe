import { describe, expect, it } from 'vitest';

import { formatDegrees, splitLongitude } from '../../resources/js/lib/zodiac';

describe('splitLongitude', () => {
    it('places a longitude in its sign', () => {
        // Sun on 15 July 1985, tropical: 112.9549° = 22°57′ Cancer.
        const { sign, degree, minute } = splitLongitude(112.9549256);

        expect(sign.key).toBe('cancer');
        expect(degree).toBe(22);
        expect(minute).toBe(57);
    });

    it('truncates, so the end of a sign never rounds into the next', () => {
        const { sign, degree, minute } = splitLongitude(59.9999);

        expect(sign.key).toBe('taurus');
        expect(`${degree}°${minute}′`).toBe('29°59′');
    });

    it('handles the edges of the circle', () => {
        expect(splitLongitude(0).sign.key).toBe('aries');
        expect(splitLongitude(359.99).sign.key).toBe('pisces');
        expect(splitLongitude(360).sign.key).toBe('aries');
        expect(splitLongitude(-0.5).sign.key).toBe('pisces');
    });

    it('does not lose a minute to floating point', () => {
        // 30° exactly, arriving as 29.999999999999996 after arithmetic.
        expect(splitLongitude(29.999999999999996).sign.key).toBe('taurus');
    });
});

describe('formatDegrees', () => {
    it('pads the minutes', () => {
        expect(formatDegrees(90.0833)).toBe('0°04′');
    });
});
