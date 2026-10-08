import { describe, expect, it } from 'vitest';

import { describeDevice, groupSecret, isWarning, normaliseCode } from '@/lib/security';

describe('describeDevice', () => {
    it('recognises the common browsers and systems', () => {
        expect(
            describeDevice(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            ),
        ).toEqual({ browser: 'Chrome', system: 'Windows' });
        expect(
            describeDevice(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
            ),
        ).toEqual({ browser: 'Edge', system: 'Windows' });
        expect(describeDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:131.0) Gecko/20100101 Firefox/131.0')).toEqual(
            { browser: 'Firefox', system: 'macOS' },
        );
        expect(
            describeDevice(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            ),
        ).toEqual({ browser: 'Safari', system: 'iOS' });
        expect(
            describeDevice(
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
            ),
        ).toEqual({ browser: 'Chrome', system: 'Android' });
    });

    it('says nothing about what it does not know', () => {
        expect(describeDevice(null)).toEqual({ browser: null, system: null });
        expect(describeDevice('curl/8.4.0')).toEqual({ browser: null, system: null });
    });
});

describe('isWarning', () => {
    it('flags what may be someone else trying the account', () => {
        expect(isWarning('login_failed')).toBe(true);
        expect(isWarning('lockout')).toBe(true);
        expect(isWarning('two_factor_failed')).toBe(true);
        expect(isWarning('recovery_code_used')).toBe(true);
        expect(isWarning('login')).toBe(false);
        expect(isWarning('password_changed')).toBe(false);
    });
});

describe('groupSecret and normaliseCode', () => {
    it('groups the setup key by four', () => {
        expect(groupSecret('JBSWY3DPEHPK3PXP')).toBe('JBSW Y3DP EHPK 3PXP');
        expect(groupSecret('ABCDEF')).toBe('ABCD EF');
        expect(groupSecret(null)).toBe('');
    });

    it('keeps six digits of a typed code', () => {
        expect(normaliseCode(' 123 456 ')).toBe('123456');
        expect(normaliseCode('12-34-56-78')).toBe('123456');
        expect(normaliseCode(undefined)).toBe('');
    });
});
