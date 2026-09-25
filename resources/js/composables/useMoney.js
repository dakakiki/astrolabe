import { useI18n } from 'vue-i18n';

import { formatMoney } from '@/lib/money';
import { useReferenceStore } from '@/stores/reference';

/**
 * Money on screen, in the viewer's locale, with each currency's own decimals
 * (reference-data `currency_decimals`, fetched once per visit); until they
 * arrive, two decimals are assumed.
 */
export function useMoney() {
    const { locale } = useI18n();
    const reference = useReferenceStore();
    reference.load().catch(() => {});

    const decimals = (currency) => reference.data?.currency_decimals?.[currency] ?? 2;

    /** { amount: 4950, currency: 'EUR' } → "€49.50"; negative amounts keep their sign. */
    const format = (money) => (money ? formatMoney(money, locale.value, decimals(money.currency)) : '');

    /** A figure in several currencies: "€420 · RSD 12,000"; "—" when empty. */
    const formatList = (list, empty = '—') => (list?.length ? list.map(format).join(' · ') : empty);

    return { decimals, format, formatList };
}
