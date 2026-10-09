import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import { decide, home, safeRedirect } from '../../resources/js/portal/guard';
import {
    appointmentWhen,
    displayZone,
    isLink,
    readToken,
    suggestedZone,
    zoneLabel,
} from '../../resources/js/portal/lib/portal';
import routes from '../../resources/js/portal/routes';

const router = createRouter({ history: createMemoryHistory(), routes });
const resolve = (path) => router.resolve(path);

const guest = { state: 'guest' };
const ready = { state: 'ready' };
const choose = { state: 'choose' };
const noAccess = { state: 'no_access' };

describe('readToken', () => {
    it('takes the token from after "#"', () => {
        const token = 'a'.repeat(48);

        expect(readToken(`#${token}`)).toBe(token);
        expect(readToken(token)).toBe(token);
    });

    it('ignores what is not one of ours', () => {
        for (const hash of ['', '#', '#short', '#has spaces in it but long enough', `#${'a'.repeat(129)}`, null]) {
            expect(readToken(hash)).toBeNull();
        }
    });
});

describe('time zones', () => {
    const practice = { timezone: 'Europe/Belgrade' };

    it("shows the client's own zone once chosen, the practice's until then", () => {
        expect(displayZone({ timezone: 'Europe/London' }, practice)).toBe('Europe/London');
        expect(displayZone({ timezone: null }, practice)).toBe('Europe/Belgrade');
        expect(displayZone(null, null)).toBe('UTC');
    });

    it("offers the device's zone only when it differs and none was chosen", () => {
        expect(suggestedZone({ timezone: null }, practice, 'Europe/London')).toBe('Europe/London');
        expect(suggestedZone({ timezone: null }, practice, 'Europe/Belgrade')).toBeNull();
        expect(suggestedZone({ timezone: 'Europe/Paris' }, practice, 'Europe/London')).toBeNull();
    });

    it('names the zone by its place and short name', () => {
        expect(zoneLabel('Europe/London', '2026-07-01T12:00:00Z', 'en-GB')).toBe('London (BST)');
        expect(zoneLabel('America/New_York', '2026-01-15T12:00:00Z', 'en-US')).toBe('New York (EST)');
    });

    it("reads an appointment on the client's clock", () => {
        const appointment = { starts_at: '2026-10-14T12:00:00Z', ends_at: '2026-10-14T13:00:00Z' };

        const london = appointmentWhen(appointment, 'en-GB', 'Europe/London');

        // ICU versions differ on the comma after the weekday.
        expect(london.date).toMatch(/^Wednesday,? 14 October 2026$/);
        expect(london.time).toBe('13:00 – 14:00');
        expect(appointmentWhen(appointment, 'en-GB', 'Asia/Tokyo').time).toBe('21:00 – 22:00');
    });

    it('crosses a daylight-saving change on the right side', () => {
        // London goes back to GMT on 25 October 2026 at 01:00 UTC.
        const before = { starts_at: '2026-10-24T09:00:00Z', ends_at: '2026-10-24T10:00:00Z' };
        const after = { starts_at: '2026-10-26T09:00:00Z', ends_at: '2026-10-26T10:00:00Z' };

        expect(appointmentWhen(before, 'en-GB', 'Europe/London').time).toBe('10:00 – 11:00');
        expect(appointmentWhen(after, 'en-GB', 'Europe/London').time).toBe('09:00 – 10:00');
    });

    it('tells a meeting link from a place', () => {
        expect(isLink('https://meet.example.com/abc')).toBe(true);
        expect(isLink('Knez Mihailova 12, Belgrade')).toBe(false);
        expect(isLink('javascript:alert(1)')).toBe(false);
    });
});

describe('portal guard', () => {
    it('sends a guest to sign in, and back afterwards', () => {
        expect(decide(resolve('/'), guest)).toEqual({ name: 'sign-in' });
        expect(decide(resolve('/shared'), guest)).toEqual({ name: 'sign-in', query: { redirect: '/shared' } });
        expect(decide(resolve('/sign-in'), guest)).toBe(true);
    });

    it('opens the pages emailed links lead to for anyone', () => {
        for (const state of [guest, ready, choose, noAccess]) {
            expect(decide(resolve('/invitation'), state)).toBe(true);
            expect(decide(resolve('/sign-in/link'), state)).toBe(true);
        }
    });

    it('needs a practice open for the practice screens', () => {
        for (const path of ['/', '/appointments', '/shared', '/profile']) {
            expect(decide(resolve(path), ready)).toBe(true);
            expect(decide(resolve(path), noAccess)).toEqual({ name: 'no-access' });
        }
        expect(decide(resolve('/'), choose)).toEqual({ name: 'choose', query: undefined });
        expect(decide(resolve('/shared'), choose)).toEqual({ name: 'choose', query: { redirect: '/shared' } });
    });

    it('keeps the account screens open without a practice', () => {
        expect(decide(resolve('/security'), noAccess)).toBe(true);
        expect(decide(resolve('/choose'), ready)).toBe(true);
        expect(decide(resolve('/no-access'), ready)).toEqual({ name: 'home' });
        expect(decide(resolve('/sign-in'), ready)).toEqual({ name: 'home' });
        expect(home(choose)).toEqual({ name: 'choose' });
    });

    it('redirects only inside the portal', () => {
        expect(safeRedirect('/shared')).toBe('/shared');
        expect(safeRedirect('//evil.example')).toBeNull();
        expect(safeRedirect('https://evil.example')).toBeNull();
        expect(safeRedirect(undefined)).toBeNull();
    });

    it('draws the signed-out pages in the card layout', () => {
        for (const path of ['/sign-in', '/sign-in/link', '/invitation', '/choose', '/no-access', '/nothing/here']) {
            expect(resolve(path).meta.layout).toBe('auth');
        }
        expect(resolve('/appointments').meta.layout).toBeUndefined();
    });
});
