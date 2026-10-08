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

    describe('a practice scheduled for deletion', () => {
        const closing = { ...verified, workspace: { deletion: { deletes_at: '2026-11-07T12:00:00Z' } } };
        const open = { ...verified, workspace: { deletion: null } };

        it('leads every screen of the app to the closing screen', () => {
            expect(resolveNavigation(route('dashboard', { verified: true }), closing)).toEqual({
                name: 'practice-deletion',
            });
            expect(resolveNavigation(route('settings.data', { verified: true }), closing)).toEqual({
                name: 'practice-deletion',
            });
        });

        it('keeps the closing screen and the export link open', () => {
            expect(resolveNavigation(route('practice-deletion', { verified: true, closing: true }), closing)).toBe(true);
            expect(resolveNavigation(route('practice-exports.link', { verified: true, closing: true }), closing)).toBe(
                true,
            );
        });

        it('sends people back to the app once the deletion is cancelled', () => {
            expect(resolveNavigation(route('practice-deletion', { verified: true, closing: true }), open)).toEqual({
                name: 'dashboard',
            });
            expect(resolveNavigation(route('dashboard', { verified: true }), open)).toBe(true);
        });

        it('still sends guests to sign in first', () => {
            expect(resolveNavigation(route('practice-deletion', { verified: true, closing: true }), guest)).toEqual({
                name: 'login',
                query: { redirect: '/practice-deletion' },
            });
        });
    });

    describe('the operator’s admin', () => {
        const admin = { user: { email_verified: true, is_admin: true, two_factor_enabled: true }, workspace: null };
        const newAdmin = { user: { email_verified: true, is_admin: true, two_factor_enabled: false }, workspace: null };

        it('sends the admin from practice screens and sign-in to the admin', () => {
            expect(resolveNavigation(route('dashboard', { verified: true }, '/'), admin)).toEqual({ name: 'admin.astrologers' });
            expect(resolveNavigation(route('clients.index', { verified: true }), admin)).toEqual({ name: 'admin.astrologers' });
            expect(resolveNavigation(route('login', { guest: true }), admin)).toEqual({ name: 'admin.astrologers' });
            expect(resolveNavigation(route('verify-email', { auth: true }), admin)).toEqual({ name: 'admin.astrologers' });
        });

        it('opens the admin screens', () => {
            expect(resolveNavigation(route('admin.system', { admin: true }), admin)).toBe(true);
        });

        it('opens only the security screen until two-factor sign-in is on', () => {
            expect(resolveNavigation(route('admin.astrologers', { admin: true }), newAdmin)).toEqual({ name: 'admin.security' });
            expect(resolveNavigation(route('admin.security', { admin: true }), newAdmin)).toBe(true);
        });

        it('keeps astrologers and guests out of the admin', () => {
            expect(resolveNavigation(route('admin.astrologers', { admin: true }), verified)).toEqual({ name: 'dashboard' });
            expect(resolveNavigation(route('admin.astrologers', { admin: true }, '/admin/astrologers'), guest)).toEqual({
                name: 'login',
                query: { redirect: '/admin/astrologers' },
            });
        });
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
