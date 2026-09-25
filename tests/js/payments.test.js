import { describe, expect, it } from 'vitest';

import { fixedCurrency, paymentDefaults, paymentFilters, periodRange, signedAmount } from '@/lib/payments';

describe('periodRange', () => {
    it('counts calendar months and years from today', () => {
        expect(periodRange('this_month', '2026-10-05')).toEqual({ from: '2026-10-01', to: '2026-10-31' });
        expect(periodRange('last_month', '2026-10-05')).toEqual({ from: '2026-09-01', to: '2026-09-30' });
        expect(periodRange('this_year', '2026-10-05')).toEqual({ from: '2026-01-01', to: '2026-12-31' });
    });

    it('crosses the year and leap February', () => {
        expect(periodRange('last_month', '2026-01-15')).toEqual({ from: '2025-12-01', to: '2025-12-31' });
        expect(periodRange('this_month', '2028-02-10')).toEqual({ from: '2028-02-01', to: '2028-02-29' });
        expect(periodRange('last_month', '2026-03-31')).toEqual({ from: '2026-02-01', to: '2026-02-28' });
    });

    it('takes the last 90 days including today, and nothing for all time', () => {
        expect(periodRange('last_90_days', '2026-10-05')).toEqual({ from: '2026-07-08', to: '2026-10-05' });
        expect(periodRange('all', '2026-10-05')).toEqual({});
    });
});

describe('paymentDefaults', () => {
    const consultation = {
        fee: { amount: 12000, currency: 'EUR' },
        billing: { balance: { amount: 7050, currency: 'EUR' }, paid: { amount: 4950, currency: 'EUR' } },
    };

    it('asks for what a consultation still owes, in its currency', () => {
        expect(paymentDefaults({ consultation, currency: 'RSD', today: '2026-10-05' })).toMatchObject({
            kind: 'payment',
            amount: '70.50',
            currency: 'EUR',
            paid_on: '2026-10-05',
        });
    });

    it('leaves the amount empty when nothing is owed, and uses the practice currency without a consultation', () => {
        const paid = { ...consultation, billing: { balance: { amount: 0, currency: 'EUR' } } };
        expect(paymentDefaults({ consultation: paid, today: '2026-10-05' }).amount).toBe('');
        expect(paymentDefaults({ currency: 'RSD', today: '2026-10-05' })).toMatchObject({ amount: '', currency: 'RSD' });
    });

    it('writes whole units for currencies without decimals', () => {
        const yen = { fee: { amount: 4900, currency: 'JPY' }, billing: { balance: { amount: 4900, currency: 'JPY' } } };
        expect(paymentDefaults({ consultation: yen, today: '2026-10-05', decimals: () => 0 }).amount).toBe('4900');
    });
});

describe('fixedCurrency', () => {
    it('is the fee’s currency, else the first payment’s, else free', () => {
        expect(fixedCurrency({ fee: { amount: 0, currency: 'CHF' } })).toBe('CHF');
        expect(fixedCurrency({ fee: null, billing: { paid: { amount: 100, currency: 'GBP' } } })).toBe('GBP');
        expect(fixedCurrency({ fee: null, billing: { paid: null } })).toBeNull();
        expect(fixedCurrency(null)).toBeNull();
    });
});

describe('signedAmount', () => {
    it('counts a refund against what came in', () => {
        expect(signedAmount({ kind: 'payment', amount: 500 })).toBe(500);
        expect(signedAmount({ kind: 'refund', amount: 500 })).toBe(-500);
    });
});

describe('paymentFilters', () => {
    it('sends only what is chosen', () => {
        expect(paymentFilters({ period: 'this_month', today: '2026-10-05', method: 'cash', search: '  Ana ' })).toEqual({
            from: '2026-10-01',
            to: '2026-10-31',
            method: 'cash',
            search: 'Ana',
        });
        expect(paymentFilters({ today: '2026-10-05', clientId: 4, kind: 'refund' })).toEqual({
            client_id: 4,
            kind: 'refund',
        });
    });
});
