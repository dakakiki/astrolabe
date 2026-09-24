import { describe, expect, it } from 'vitest';

import { resolveNavigation, safeRedirect } from '../../resources/js/router/guard';

const verified = { user: { email_verified: true } };
const unverified = { user: { email_verified: false } };
const guest = { user: null };

const route = (name, meta = {}, fullPath = `/${name}`) => ({ name, meta, fullPath });

describe('resolveNavigation', () => {
    it('sends guests to sign in, remembering where they were going', () => {
        expect(resolveNavigation(route('settings.profile', { verified: true }, '/settings/profile'), guest)).toEqual({
            name: 'login',
            query: { redirect: '/settings/profile' },
        });
    });

    it('does not add a redirect for the start page', () => {
        expect(resolveNavigation(route('dashboard', { verified: true }, '/'), guest)).toEqual({ name: 'login' });
    });

    it('keeps signed-in users away from guest screens', () => {
        expect(resolveNavigation(route('login', { guest: true }), verified)).toEqual({ name: 'dashboard' });
        expect(resolveNavigation(route('register', { guest: true }), unverified)).toEqual({ name: 'dashboard' });
    });

    it('holds unverified users at the verification notice', () => {
        expect(resolveNavigation(route('dashboard', { verified: true }), unverified)).toEqual({ name: 'verify-email' });
        expect(resolveNavigation(route('verify-email', { auth: true }), unverified)).toBe(true);
    });

    it('lets unverified users open the link from their email', () => {
        expect(resolveNavigation(route('verify-email-link', { auth: true }), unverified)).toBe(true);
    });

    it('moves verified users past the verification notice', () => {
        expect(resolveNavigation(route('verify-email', { auth: true }), verified)).toEqual({ name: 'dashboard' });
    });

    it('lets verified users into the app', () => {
        expect(resolveNavigation(route('dashboard', { verified: true }), verified)).toBe(true);
    });

    it('leaves public pages alone', () => {
        expect(resolveNavigation(route('not-found'), guest)).toBe(true);
    });
});

describe('safeRedirect', () => {
    it('accepts paths inside the app', () => {
        expect(safeRedirect('/settings/profile')).toBe('/settings/profile');
    });

    it('rejects anything that could leave the app', () => {
        expect(safeRedirect('https://evil.example')).toBeNull();
        expect(safeRedirect('//evil.example')).toBeNull();
        expect(safeRedirect('javascript:alert(1)')).toBeNull();
        expect(safeRedirect(undefined)).toBeNull();
        expect(safeRedirect(['/a', '/b'])).toBeNull();
    });
});
