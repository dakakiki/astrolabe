import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import { layoutOf } from '../../resources/js/lib/layout';
import routes from '../../resources/js/router/routes';

const router = createRouter({ history: createMemoryHistory(), routes });

describe('layoutOf', () => {
    it('keeps the layout a route names', () => {
        expect(layoutOf({ layout: 'auth' })).toBe('auth');
        expect(layoutOf({ layout: 'bare' })).toBe('bare');
    });

    it('draws everything else in the app shell', () => {
        expect(layoutOf({})).toBe('app');
        expect(layoutOf(undefined)).toBe('app');
        expect(layoutOf({ layout: 'unknown' })).toBe('app');
    });

    it('shows the legal documents and the not-found page without the app shell', () => {
        for (const path of ['/legal/terms', '/legal/dpa', '/legal/privacy', '/no/such/page']) {
            expect(layoutOf(router.resolve(path).meta)).toBe('bare');
        }
        expect(layoutOf(router.resolve('/legal/accept').meta)).toBe('auth');
        expect(layoutOf(router.resolve('/clients').meta)).toBe('app');
    });
});
