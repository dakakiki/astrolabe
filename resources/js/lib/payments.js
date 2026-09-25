import { addDays, addMonths, startOfMonth } from '@/lib/calendar';
import { fromMinorUnits } from '@/lib/money';

/**
 * Payments and a consultation's billing (docs/spec/02, "Plaćanja"). The server
 * derives the billing status and balance; this picks periods, fills the form
 * and words what the server sent.
 */

export const PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'paypal', 'other'];

export const PERIODS = ['this_month', 'last_month', 'last_90_days', 'this_year', 'all'];

/** Badge tone per billing status; the label is always shown too (doc 09). */
export const BILLING_TONES = {
    no_charge: '',
    unpaid: 'b-warn',
    partially_paid: 'b-info',
    paid: 'b-ok',
    refunded: '',
};

/**
 * `from` / `to` days for a period, counted from today on the viewer's
 * calendar; `all` has neither.
 */
export function periodRange(period, today) {
    switch (period) {
        case 'this_month':
            return { from: startOfMonth(today), to: addDays(addMonths(today, 1), -1) };
        case 'last_month':
            return { from: addMonths(today, -1), to: addDays(startOfMonth(today), -1) };
        case 'last_90_days':
            return { from: addDays(today, -89), to: today };
        case 'this_year':
            return { from: `${today.slice(0, 4)}-01-01`, to: `${today.slice(0, 4)}-12-31` };
        default:
            return {};
    }
}

/**
 * Starting values for a new payment: for a consultation, what it still owes
 * (in its currency); for a refund, nothing typed in yet; otherwise the
 * practice's currency.
 */
export function paymentDefaults({ consultation = null, currency = 'EUR', today, decimals = () => 2 }) {
    const balance = consultation?.billing?.balance;
    const fixed = consultation?.fee?.currency ?? consultation?.billing?.paid?.currency ?? null;
    const chosen = fixed ?? currency;

    return {
        kind: 'payment',
        amount: balance && balance.amount > 0 ? fromMinorUnits(balance.amount, decimals(chosen)) : '',
        currency: chosen,
        paid_on: today,
        method: '',
        reference: '',
        notes: '',
    };
}

/** The currency a consultation's payments must be in, or null when any will do. */
export function fixedCurrency(consultation) {
    return consultation?.fee?.currency ?? consultation?.billing?.paid?.currency ?? null;
}

/** Signed, for sums and CSV-like reading: a refund counts against what came in. */
export function signedAmount(payment) {
    return payment.kind === 'refund' ? -payment.amount : payment.amount;
}

/** Query parameters for the payments list and its export, without empty ones. */
export function paymentFilters({ period = 'all', today, method = '', kind = '', clientId = null, search = '' }) {
    const params = { ...periodRange(period, today) };
    if (method) params.method = method;
    if (kind) params.kind = kind;
    if (clientId) params.client_id = clientId;
    if (search.trim()) params.search = search.trim();

    return params;
}
