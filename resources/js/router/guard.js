/**
 * Where a navigation should go, given the route's meta and the session.
 * Kept free of Vue and the router so it can be tested on its own.
 *
 * Route meta:
 *   guest    — only for signed-out visitors (sign in, register …)
 *   auth     — needs a signed-in user
 *   verified — additionally needs a verified email address
 *   closing  — still open while the practice is scheduled for deletion
 *   legal    — still open while new terms wait to be accepted (Phase 8c)
 *   admin    — the operator's admin (Phase 8c)
 *
 * A practice scheduled for deletion (`workspace.deletion`) is closed: every
 * other verified screen leads to the closing screen (Phase 8b).
 *
 * A new version of the Terms or the DPA (`user.legal.pending`) closes the
 * practice too, until it is accepted: every verified screen but those marked
 * `legal` leads to the acceptance screen (Phase 8c). Public pages without
 * meta (the legal documents themselves) stay open to everyone.
 *
 * The admin account has no practice: it sees only admin screens, and until
 * two-factor sign-in is on only the admin's Security screen. Astrologers never
 * see admin screens (the server refuses them anyway).
 *
 * @param {{ name?: string, fullPath: string, meta: Record<string, unknown> }} to
 * @param {{ user: { email_verified: boolean, is_admin?: boolean, two_factor_enabled?: boolean, legal?: { pending: string[] } | null } | null, workspace?: { deletion: object | null } | null }} session
 * @returns {true | { name: string, query?: Record<string, string> }}
 */
export function resolveNavigation(to, { user, workspace = null }) {
    const { guest, auth, verified, closing, legal, admin } = to.meta;
    const home = user?.is_admin ? { name: 'admin.astrologers' } : { name: 'dashboard' };

    if (guest && user) {
        return home;
    }

    if ((auth || verified || admin) && !user) {
        return to.fullPath === '/' ? { name: 'login' } : { name: 'login', query: { redirect: to.fullPath } };
    }

    if (user?.is_admin) {
        if (admin) {
            return user.two_factor_enabled || to.name === 'admin.security' ? true : { name: 'admin.security' };
        }

        // Practice screens, the closing screen and the email notice are not the admin's.
        return verified || to.name === 'verify-email' ? home : true;
    }

    if (admin) {
        return home;
    }

    if (verified && !user.email_verified) {
        return { name: 'verify-email' };
    }

    if (to.name === 'verify-email' && user?.email_verified) {
        return home;
    }

    const closed = Boolean(workspace?.deletion);

    if (verified && closed && !closing) {
        return { name: 'practice-deletion' };
    }

    if (to.name === 'practice-deletion' && !closed) {
        return home;
    }

    const pending = (user?.legal?.pending ?? []).length > 0;

    if (verified && pending && !closed && !legal) {
        return to.fullPath === '/'
            ? { name: 'legal.accept' }
            : { name: 'legal.accept', query: { redirect: to.fullPath } };
    }

    if (to.name === 'legal.accept' && !pending) {
        return home;
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
