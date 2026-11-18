// Query keys that only pick a tab inside the same page: the client profile's ?tab= and the
// consultation page's ?panel= (notes / files / follow-ups, half-way down the page).
const TAB_KEYS = ['tab', 'panel'];

/**
 * Where the window goes after a navigation: back to the top for a new page, but nowhere when only
 * a tab changed — clicking a tab low on the page used to throw the reader back to the top.
 */
export function scrollFor(to, from) {
    if (from?.matched?.length && to.path === from.path) {
        const keys = new Set([...Object.keys(to.query), ...Object.keys(from.query)]);
        const onlyTabs = [...keys].every((key) => TAB_KEYS.includes(key) || to.query[key] === from.query[key]);
        if (onlyTabs) return false;
    }

    return { top: 0 };
}
