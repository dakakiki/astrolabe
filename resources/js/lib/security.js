/**
 * Wording helpers for Settings → Security (Phase 8a): the audit log's account
 * events and the browser a sign-in came from. Pure, covered by Vitest.
 */

/** Events that may mean someone else is trying the account. */
const WARNINGS = new Set(['login_failed', 'lockout', 'two_factor_failed', 'recovery_code_used']);

export function isWarning(event) {
    return WARNINGS.has(event);
}

const BROWSERS = [
    // Order matters: Edge and Opera also say "Chrome", Chrome also says "Safari".
    ['Edge', /Edg(e|A|iOS)?\/[\d.]+/],
    ['Opera', /(OPR|Opera)\/[\d.]+/],
    ['Firefox', /(Firefox|FxiOS)\/[\d.]+/],
    ['Chrome', /(Chrome|CriOS)\/[\d.]+/],
    ['Safari', /Version\/[\d.]+.*Safari\//],
];

const SYSTEMS = [
    ['iOS', /iPhone|iPad|iPod/],
    ['Android', /Android/],
    ['Windows', /Windows/],
    ['macOS', /Mac OS X|Macintosh/],
    ['Linux', /Linux|X11/],
];

/**
 * "Firefox", "macOS" — or null for what is not recognised — from a User-Agent
 * string. Good enough to recognise one's own devices, not more.
 */
export function describeDevice(userAgent) {
    if (!userAgent) {
        return { browser: null, system: null };
    }

    const browser = BROWSERS.find(([, pattern]) => pattern.test(userAgent))?.[0] ?? null;
    const system = SYSTEMS.find(([, pattern]) => pattern.test(userAgent))?.[0] ?? null;

    return { browser, system };
}

/** The setup key in groups of four, easier to type into an authenticator app. */
export function groupSecret(secret) {
    return (
        (secret ?? '')
            .replace(/\s+/g, '')
            .match(/.{1,4}/g)
            ?.join(' ') ?? ''
    );
}

/** What a person types as a code: digits only, at most six. */
export function normaliseCode(value) {
    return String(value ?? '')
        .replace(/\D+/g, '')
        .slice(0, 6);
}
