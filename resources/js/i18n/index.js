import { createI18n } from 'vue-i18n';

import en from './locales/en.json';

// English is the default and the fallback (doc 06). A further locale is a JSON
// file with the same keys, registered here and in config/astrolabe.php.
const messages = { en };

const i18n = createI18n({
    legacy: false,
    locale: document.documentElement.lang || 'en',
    fallbackLocale: 'en',
    messages,
});

/** Switch the interface language, ignoring locales without translations. */
export function setLocale(locale) {
    if (locale && messages[locale]) {
        i18n.global.locale.value = locale;
        document.documentElement.lang = locale;
    }
}

export default i18n;
