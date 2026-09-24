import { describe, expect, it } from 'vitest';

import { joinPhone, splitPhone } from '../../resources/js/lib/phone';

const countries = [
    { code: 'RS', phone_code: '381' },
    { code: 'US', phone_code: '1' },
    { code: 'CA', phone_code: '1' },
    { code: 'AS', phone_code: '1-684' },
    { code: 'ME', phone_code: '382' },
    { code: 'AQ', phone_code: null },
];

describe('splitPhone', () => {
    it('finds the country from the prefix and keeps the rest as written', () => {
        expect(splitPhone('+381 60 123 4567', countries)).toEqual({ country: 'RS', number: '60 123 4567' });
    });

    it('prefers the longest matching code', () => {
        expect(splitPhone('+1-684 633 1234', countries)).toEqual({ country: 'AS', number: '633 1234' });
        expect(splitPhone('+1 684 633 1234', countries).country).toBe('AS');
    });

    it('uses the preferred country when a code is shared', () => {
        expect(splitPhone('+1 416 555 0100', countries, 'CA').country).toBe('CA');
        expect(splitPhone('+1 212 555 0100', countries, 'RS').country).toBe('US');
    });

    it('treats a number without "+" as local to the preferred country', () => {
        expect(splitPhone('060 123 4567', countries, 'RS')).toEqual({ country: 'RS', number: '060 123 4567' });
        expect(splitPhone(null, countries, 'ME')).toEqual({ country: 'ME', number: '' });
    });
});

describe('joinPhone', () => {
    it('puts the dialling code in front', () => {
        expect(joinPhone('RS', '60 123 4567', countries)).toBe('+381 60 123 4567');
    });

    it('leaves a number with its own "+" alone and empties to null', () => {
        expect(joinPhone('RS', '+44 20 7946 0000', countries)).toBe('+44 20 7946 0000');
        expect(joinPhone('RS', '  ', countries)).toBeNull();
    });

    it('round-trips', () => {
        const { country, number } = splitPhone('+382 67 123 456', countries);
        expect(joinPhone(country, number, countries)).toBe('+382 67 123 456');
    });
});
