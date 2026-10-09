import { describe, expect, it } from 'vitest';

import {
    formatLegalDate,
    internalPath,
    pendingDocuments,
    privacyNotice,
    versionsOf,
} from '../../resources/js/lib/legal';

const user = (legal) => ({ email_verified: true, legal });

describe('pendingDocuments', () => {
    it('reads what the server says must be accepted', () => {
        expect(pendingDocuments(user({ pending: ['terms'], updated: [], current: {} }))).toEqual(['terms']);
    });

    it('has nothing for guests and the admin', () => {
        expect(pendingDocuments(null)).toEqual([]);
        expect(pendingDocuments(user(null))).toEqual([]);
    });
});

describe('privacyNotice', () => {
    it('gives the new privacy policy version until it has been seen', () => {
        const legal = { pending: [], updated: ['privacy'], current: { privacy: '2026-11-01' } };

        expect(privacyNotice(user(legal))).toBe('2026-11-01');
        expect(privacyNotice(user({ ...legal, updated: [] }))).toBeNull();
        expect(privacyNotice(null)).toBeNull();
    });
});

describe('versionsOf', () => {
    it('lists the versions shown, by document', () => {
        expect(
            versionsOf([
                { slug: 'terms', version: '2026-10-09' },
                { slug: 'dpa', version: '2026-10-09-2' },
            ]),
        ).toEqual({ terms: '2026-10-09', dpa: '2026-10-09-2' });
    });
});

describe('formatLegalDate', () => {
    it('words the date the same in every time zone', () => {
        expect(formatLegalDate('2026-10-09', 'en-GB')).toBe('9 October 2026');
    });

    it('leaves a missing date empty', () => {
        expect(formatLegalDate(null, 'en')).toBe('');
    });
});

describe('internalPath', () => {
    it('opens links between the documents inside the app', () => {
        expect(internalPath('/legal/dpa')).toBe('/legal/dpa');
        expect(internalPath('/legal/terms#3-your-account')).toBe('/legal/terms#3-your-account');
    });

    it('leaves other links to the browser', () => {
        expect(internalPath('https://www.poverenik.rs')).toBeNull();
        expect(internalPath('//evil.example')).toBeNull();
        expect(internalPath('mailto:hello@example.com')).toBeNull();
        expect(internalPath(null)).toBeNull();
    });
});
