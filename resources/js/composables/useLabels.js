import { useI18n } from 'vue-i18n';

/**
 * Display names for values the API sends as codes. Built-in methods are
 * translated by slug; a workspace's own methods show the name it gave them.
 */
export function useLabels() {
    const { t, te, locale } = useI18n();

    const fromKey = (group, value) => (value && te(`${group}.${value}`) ? t(`${group}.${value}`) : (value ?? ''));

    function currency(code) {
        try {
            return `${code} — ${new Intl.DisplayNames([locale.value], { type: 'currency' }).of(code)}`;
        } catch {
            return code;
        }
    }

    function language(code, fallback) {
        try {
            return new Intl.DisplayNames([code], { type: 'language' }).of(code) ?? fallback;
        } catch {
            return fallback ?? code;
        }
    }

    return {
        houseSystem: (value) => fromKey('houseSystems', value),
        zodiacMode: (value) => fromKey('zodiacModes', value),
        ayanamsa: (value) => fromKey('ayanamsas', value),
        role: (value) => fromKey('roles', value),
        method: (method) => (method.is_system ? fromKey('methods', method.slug) : method.name),
        currency,
        language,
    };
}

/** IANA time zones known to this browser, always including UTC and the current value. */
export function timeZoneOptions(current) {
    let zones = [];
    try {
        zones = Intl.supportedValuesOf('timeZone');
    } catch {
        zones = [];
    }

    return [...new Set(['UTC', ...zones, ...(current ? [current] : [])])].sort((a, b) =>
        a === 'UTC' ? -1 : b === 'UTC' ? 1 : a.localeCompare(b),
    );
}
