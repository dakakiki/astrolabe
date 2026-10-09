import { createI18n } from 'vue-i18n';

import en from './locales/en.json';

// The portal's own strings (`portal.*`), plus the few keys the components it
// shares with the application read (theme toggle, phone field). English first,
// ready for more languages like the application (docs/spec/06).
const messages = { en };

const i18n = createI18n({
    legacy: false,
    locale: document.documentElement.lang || 'en',
    fallbackLocale: 'en',
    messages,
});

export function setLocale(locale) {
    if (locale && messages[locale]) {
        i18n.global.locale.value = locale;
        document.documentElement.lang = locale;
    }
}

export default i18n;
