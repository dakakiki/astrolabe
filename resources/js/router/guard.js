/**
 * Where a navigation should go, given the route's meta and the session.
 * Kept free of Vue and the router so it can be tested on its own.
 *
 * Route meta:
 *   guest    — only for signed-out visitors (sign in, register …)
 *   auth     — needs a signed-in user
 *   verified — additionally needs a verified email address
 *   closing  — still open while the practice is scheduled for deletion
 *
 * A practice scheduled for deletion (`workspace.deletion`) is closed: every
 * other verified screen leads to the closing screen (Phase 8b).
 *
 * @param {{ name?: string, fullPath: string, meta: Record<string, unknown> }} to
 * @param {{ user: { email_verified: boolean } | null, workspace?: { deletion: object | null } | null }} session
 * @returns {true | { name: string, query?: Record<string, string> }}
 */
export function resolveNavigation(to, { user, workspace = null }) {
    const { guest, auth, verified, closing } = to.meta;

    if (guest && user) {
        return { name: 'dashboard' };
    }

    if ((auth || verified) && !user) {
        return to.fullPath === '/' ? { name: 'login' } : { name: 'login', query: { redirect: to.fullPath } };
    }

    if (verified && !user.email_verified) {
        return { name: 'verify-email' };
    }

    if (to.name === 'verify-email' && user?.email_verified) {
        return { name: 'dashboard' };
    }

    const closed = Boolean(workspace?.deletion);

    if (verified && closed && !closing) {
        return { name: 'practice-deletion' };
    }

    if (to.name === 'practice-deletion' && !closed) {
        return { name: 'dashboard' };
    }

    return true;
}

/**
 * A post-login redirect target taken from the query string, limited to paths
 * inside this app so the parameter cannot send users to another site.
 */
export function safeRedirect(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : null;
}
