/**
 * Wording helpers for the operator's admin (Phase 8c). Pure, covered by Vitest.
 */

/** The one state worth showing first for an astrologer's account. */
export function astrologerState(astrologer) {
    if (astrologer.suspended_at) return 'suspended';
    if (astrologer.practice?.deletes_at) return 'closing';
    if (!astrologer.email_verified) return 'unverified';

    return 'active';
}

/** Badge colour class for a state ('' is the plain badge). */
export function stateTone(state) {
    return { suspended: 'b-danger', closing: 'b-warn', active: 'b-ok' }[state] ?? '';
}

/**
 * An audit entry's properties as short "key: value" pairs, in a stable order.
 * They hold names, counts and the operator's reasons — never content.
 */
export function propertyPairs(properties) {
    if (!properties || typeof properties !== 'object') {
        return [];
    }

    return Object.entries(properties)
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([key, value]) => [key.replaceAll('_', ' '), formatValue(value)]);
}

function formatValue(value) {
    if (Array.isArray(value)) return value.map(formatValue).join(', ');
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    if (typeof value === 'object')
        return propertyPairs(value)
            .map(([key, inner]) => `${key} ${inner}`)
            .join(', ');

    return String(value);
}

/** Query parameters for the API: empty filters left out. */
export function cleanQuery(filters) {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== undefined && value !== '' && value !== false,
        ),
    );
}
