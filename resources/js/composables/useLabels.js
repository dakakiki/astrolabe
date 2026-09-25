import { useI18n } from 'vue-i18n';

import { leadTime } from '@/lib/notifications';

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

    /** Country name in the viewer's language; the code itself if the browser does not know it. */
    function country(code) {
        if (!code) return '';
        try {
            return new Intl.DisplayNames([locale.value], { type: 'region' }).of(code) ?? code;
        } catch {
            return code;
        }
    }

    /** A language name in the viewer's language (for a client's preferred language). */
    function languageName(code) {
        if (!code) return '';
        try {
            return new Intl.DisplayNames([locale.value], { type: 'language' }).of(code) ?? code;
        } catch {
            return code;
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
        country,
        languageName,
        timeAccuracy: (value) => fromKey('timeAccuracy', value ? `${value}.label` : value),
        status: (value) => fromKey('statuses', value),
        locationType: (value) => fromKey('locationTypes', value),
        serviceColor: (value) => fromKey('serviceColors', value),
        relationship: (value) => fromKey('relationshipTypes', value),
        /** A chart point: a body, or an angle (asc, mc …). */
        point: (key) => (te(`chart.angles.${key}`) ? t(`chart.angles.${key}`) : fromKey('bodies', key)),
        /** A reminder's lead time in minutes: "1 day before", "2 hours before" … */
        leadTime: (minutes) => {
            const lead = leadTime(minutes);

            return t(`notifications.lead.${lead.unit}`, { count: lead.count }, lead.count);
        },
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

/**
 * Languages a client may prefer for communication. Any ISO 639-1 code is accepted
 * by the server; this is the short list offered in the form.
 */
export const CLIENT_LANGUAGES = [
    'sr',
    'hr',
    'bs',
    'sl',
    'mk',
    'en',
    'de',
    'fr',
    'it',
    'es',
    'pt',
    'nl',
    'ru',
    'uk',
    'pl',
    'cs',
    'sk',
    'hu',
    'ro',
    'bg',
    'el',
    'tr',
    'sv',
    'no',
    'da',
    'fi',
    'ar',
    'he',
    'hi',
    'zh',
    'ja',
    'ko',
];
