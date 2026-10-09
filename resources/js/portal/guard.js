/**
 * Where a portal route may go, from the route's meta and the session state
 * (docs/spec/12, "Prijava", step 4). Pure, covered by Vitest.
 *
 * meta: `public` (anyone: the email link pages), `guest` (signed out only:
 * sign-in), `auth` (signed in), `practice` (signed in with a practice open).
 *
 * @returns {true|{name: string, query?: object}}
 */
export function decide(to, session) {
    const meta = to.meta ?? {};

    if (meta.public) return true;

    const signedIn = session.state !== 'guest';

    if (meta.guest) {
        return signedIn ? home(session) : true;
    }

    if (!signedIn) {
        return to.name === 'home' || !to.fullPath
            ? { name: 'sign-in' }
            : { name: 'sign-in', query: { redirect: to.fullPath } };
    }

    if (to.name === 'no-access') {
        return session.state === 'no_access' ? true : home(session);
    }

    if (meta.practice && session.state !== 'ready') {
        return session.state === 'choose'
            ? { name: 'choose', query: to.name === 'home' ? undefined : { redirect: to.fullPath } }
            : { name: 'no-access' };
    }

    return true;
}

/** Where a signed-in person starts. */
export function home(session) {
    if (session.state === 'choose') return { name: 'choose' };
    if (session.state === 'no_access') return { name: 'no-access' };

    return { name: 'home' };
}

/** A redirect back inside the portal only (never another site). */
export function safeRedirect(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : null;
}
