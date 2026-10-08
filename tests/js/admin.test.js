import { describe, expect, it } from 'vitest';

import { astrologerState, cleanQuery, propertyPairs, stateTone } from '@/lib/admin';

import en from '../../resources/js/i18n/locales/en.json';

describe('astrologerState', () => {
    const base = { suspended_at: null, email_verified: true, practice: { deletes_at: null } };

    it('shows the state that matters most', () => {
        expect(astrologerState(base)).toBe('active');
        expect(astrologerState({ ...base, email_verified: false })).toBe('unverified');
        expect(astrologerState({ ...base, practice: { deletes_at: '2026-11-07T12:00:00Z' } })).toBe('closing');
        expect(astrologerState({ ...base, email_verified: false, suspended_at: '2026-10-09T08:00:00Z' })).toBe('suspended');
        expect(astrologerState({ ...base, practice: null })).toBe('active');
    });

    it('has a label and a tone for every state', () => {
        for (const state of ['active', 'unverified', 'closing', 'suspended']) {
            expect(en.admin.astrologers.statuses[state]).toBeTruthy();
            expect(typeof stateTone(state)).toBe('string');
        }
        expect(stateTone('suspended')).toBe('b-danger');
    });
});

describe('propertyPairs', () => {
    it('lists names and counts, flattening lists and nested counts', () => {
        expect(propertyPairs({ screen: 'astrologers', filters: ['search', 'status'] })).toEqual([
            ['screen', 'astrologers'],
            ['filters', 'search, status'],
        ]);
        expect(propertyPairs({ payments_kept: 1, permanently: true, empty: null })).toEqual([
            ['payments kept', '1'],
            ['permanently', 'yes'],
        ]);
        expect(propertyPairs({ counts: { clients: 2, notes: 1 } })).toEqual([['counts', 'clients 2, notes 1']]);
    });

    it('copes with no properties', () => {
        expect(propertyPairs(null)).toEqual([]);
        expect(propertyPairs(undefined)).toEqual([]);
    });
});

describe('cleanQuery', () => {
    it('leaves out empty filters', () => {
        expect(cleanQuery({ email: '', event: 'login', warnings: false, from: null, page: 2 })).toEqual({
            event: 'login',
            page: 2,
        });
    });
});

describe('audit event labels', () => {
    it('has a label for the events the old settings page knew and the new ones', () => {
        for (const event of ['login', 'login_failed', 'client_erased', 'admin_viewed', 'account_suspended', 'feedback_sent']) {
            expect(en.admin.events[event]).toBeTruthy();
        }
    });
});
