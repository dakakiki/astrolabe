/**
 * Which layout a route is drawn in, from its `meta.layout`:
 * - 'auth' — the sign-in card (login, registration, legal acceptance …);
 * - 'bare' — no layout at all: the page draws its own header (legal documents, not found);
 * - anything else — 'app', the sidebar and top bar.
 *
 * App.vue maps the name to a component. Kept as a name rather than a lookup with `??`,
 * because 'bare' maps to no component and a nullish fallback would turn it into 'app'.
 */
export const LAYOUTS = ['app', 'auth', 'bare'];

export function layoutOf(meta) {
    const name = meta?.layout;

    return LAYOUTS.includes(name) ? name : 'app';
}
