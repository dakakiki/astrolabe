/**
 * Money as the API sends it: an integer amount in the currency's smallest unit
 * and an ISO 4217 code — { amount: 4900, currency: 'EUR' } is 49.00 EUR.
 * `decimals` comes from reference-data `currency_decimals` (2 for most
 * currencies, 0 for e.g. JPY). The arithmetic is done on the digits, never in
 * floating point, so 0.29 does not turn into 28.
 */

/** "49.5" or "49,50" as typed → 4950; null when empty; NaN when it is not an amount. */
export function toMinorUnits(input, decimals = 2) {
    const text = String(input ?? '')
        .trim()
        .replace(/\s/g, '');
    if (text === '') return null;

    // A comma is a decimal separator in many locales; thousands separators are not accepted.
    const normalized = text.replace(',', '.');
    if (!/^\d+(\.\d+)?$/.test(normalized)) return NaN;

    const [whole, fraction = ''] = normalized.split('.');
    if (fraction.length > decimals) return NaN;

    return Number(whole) * 10 ** decimals + Number(fraction.padEnd(decimals, '0') || 0);
}

/** 4950 → "49.50", the value a form field starts from. */
export function fromMinorUnits(amount, decimals = 2) {
    if (amount === null || amount === undefined) return '';
    if (decimals === 0) return String(amount);

    const digits = String(amount).padStart(decimals + 1, '0');

    return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

/** { amount: 4950, currency: 'EUR' } → "€49.50" in the viewer's locale; whole amounts without decimals. */
export function formatMoney(money, locale, decimals = 2) {
    if (!money) return '';

    const whole = money.amount % 10 ** decimals === 0;

    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: money.currency,
            minimumFractionDigits: whole ? 0 : decimals,
            maximumFractionDigits: decimals,
        }).format(money.amount / 10 ** decimals);
    } catch {
        return `${fromMinorUnits(money.amount, decimals)} ${money.currency}`;
    }
}
