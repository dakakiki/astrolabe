import { describe, expect, it } from 'vitest';

import { formatMoney, fromMinorUnits, toMinorUnits } from '../../resources/js/lib/money';

describe('toMinorUnits', () => {
    it('turns a typed price into the smallest unit without floating-point error', () => {
        expect(toMinorUnits('49.5')).toBe(4950);
        expect(toMinorUnits('49,50')).toBe(4950);
        expect(toMinorUnits('0.29')).toBe(29);
        expect(toMinorUnits(' 120 ')).toBe(12000);
        expect(toMinorUnits('4900', 0)).toBe(4900);
    });

    it('is null when empty and NaN when it is not an amount', () => {
        expect(toMinorUnits('')).toBeNull();
        expect(toMinorUnits(null)).toBeNull();
        expect(toMinorUnits('abc')).toBeNaN();
        expect(toMinorUnits('-5')).toBeNaN();
        expect(toMinorUnits('1,200.50')).toBeNaN();
        expect(toMinorUnits('9.999')).toBeNaN();
        expect(toMinorUnits('10.5', 0)).toBeNaN();
    });
});

describe('fromMinorUnits', () => {
    it('writes the amount back for a form field', () => {
        expect(fromMinorUnits(4950)).toBe('49.50');
        expect(fromMinorUnits(5)).toBe('0.05');
        expect(fromMinorUnits(4900, 0)).toBe('4900');
        expect(fromMinorUnits(null)).toBe('');
    });
});

describe('formatMoney', () => {
    it('formats in the viewer locale and drops decimals for whole amounts', () => {
        expect(formatMoney({ amount: 12000, currency: 'EUR' }, 'en-GB')).toBe('€120');
        expect(formatMoney({ amount: 4950, currency: 'EUR' }, 'en-GB')).toBe('€49.50');
        expect(formatMoney({ amount: 4900, currency: 'JPY' }, 'en-US', 0)).toBe('¥4,900');
        expect(formatMoney(null, 'en')).toBe('');
    });
});
