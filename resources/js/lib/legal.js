/**
 * Terms of Service, Data Processing Agreement and Privacy Policy (Phase 8c).
 * The server decides what is in force and what a person still has to accept
 * (`user.legal` from /me); these helpers only read it and word it.
 */

/** The documents, in the order they are listed everywhere. */
export const LEGAL_DOCUMENTS = ['terms', 'dpa', 'privacy'];

/** Documents the person must accept before their practice opens. */
export function pendingDocuments(user) {
    return user?.legal?.pending ?? [];
}

/** The version of the privacy policy the person has not seen yet, or null. */
export function privacyNotice(user) {
    return user?.legal?.updated?.includes('privacy') ? (user.legal.current?.privacy ?? null) : null;
}

/**
 * `{ terms: '2026-10-09', … }` for the documents shown, in the versions
 * shown — what the server records as accepted.
 */
export function versionsOf(documents) {
    return Object.fromEntries(documents.map((document) => [document.slug, document.version]));
}

/** A document's date (`2026-10-09`) in words, independent of the viewer's zone. */
export function formatLegalDate(date, locale) {
    if (!date) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeZone: 'UTC' }).format(
            new Date(`${date}T00:00:00Z`),
        );
    } catch {
        return date;
    }
}

/**
 * A link inside a document that the app can open itself (`/legal/dpa`,
 * `/legal/terms#3-your-account`), or null for anything else.
 */
export function internalPath(href) {
    return typeof href === 'string' && href.startsWith('/') && !href.startsWith('//') ? href : null;
}
