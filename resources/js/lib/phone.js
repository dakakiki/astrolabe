/**
 * Phone numbers are stored as typed, with an international prefix in front:
 * "+381 60 1234567". These helpers split such a value into a country (for the
 * dialling-code picker) and the local part, and put the two back together.
 *
 * Dialling codes come from GeoNames: "381", or "1-684" for a North American
 * area code. Several countries can share one (+1, +7, +44).
 */

const digits = (code) => code.replace(/\D/g, '');

/**
 * @param {string|null} value  stored phone number
 * @param {{code: string, phone_code: string|null}[]} countries
 * @param {string|null} preferred  country to pick when several share the prefix
 * @returns {{country: string|null, number: string}}
 */
export function splitPhone(value, countries, preferred = null) {
    const text = (value ?? '').trim();

    if (!text.startsWith('+')) {
        return { country: preferred, number: text };
    }

    const typed = digits(text);
    let best = null;

    for (const country of countries) {
        if (!country.phone_code) continue;
        const code = digits(country.phone_code);

        if (!typed.startsWith(code)) continue;

        const longer = !best || code.length > best.length;
        const samePreferred = best && code.length === best.length && country.code === preferred;

        if (longer || samePreferred) {
            best = { country: country.code, length: code.length, prefix: country.phone_code };
        }
    }

    if (!best) {
        return { country: preferred, number: text };
    }

    // Drop the prefix as it was typed ("+381", "+1-684", "+1 684") and keep the rest as written.
    const rest = text.slice(1).replace(new RegExp(`^${best.prefix.split('').join('[\\s-]?')}`), '');

    return { country: best.country, number: rest.trim() };
}

/**
 * @returns {string|null} "+381 60 1234567", the number unchanged if it already
 *   carries its own "+" prefix, or null when empty
 */
export function joinPhone(country, number, countries) {
    const local = (number ?? '').trim();

    if (!local) return null;
    if (local.startsWith('+')) return local;

    const code = countries.find((c) => c.code === country)?.phone_code;

    return code ? `+${code} ${local}` : local;
}
