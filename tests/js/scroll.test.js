import { describe, expect, it } from 'vitest';

import { scrollFor } from '@/router/scroll';

const at = (path, query = {}) => ({ path, query, matched: [{}] });

describe('scrollFor', () => {
    it('goes to the top of a new page', () => {
        expect(scrollFor(at('/clients/1'), at('/clients'))).toEqual({ top: 0 });
        expect(scrollFor(at('/consultations/4'), { path: '/', query: {}, matched: [] })).toEqual({ top: 0 });
    });

    it('stays where it is when only a tab changes', () => {
        expect(scrollFor(at('/consultations/4', { tab: 'files' }), at('/consultations/4'))).toBe(false);
        expect(scrollFor(at('/clients/1', { tab: 'notes' }), at('/clients/1', { tab: 'overview' }))).toBe(false);
    });

    it('goes to the top when anything else in the address changes', () => {
        expect(scrollFor(at('/clients', { page: '2' }), at('/clients', { page: '1' }))).toEqual({ top: 0 });
        expect(
            scrollFor(
                at('/clients/1', { tab: 'transits', at: '2026-11-18T10:00' }),
                at('/clients/1', { tab: 'chart' }),
            ),
        ).toEqual({ top: 0 });
    });
});
