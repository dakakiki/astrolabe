import { createI18n } from 'vue-i18n';

import en from './locales/en.json';

// English is the default and the fallback (doc 06). Further locales are added
// as separate JSON files with the same keys.
export default createI18n({
    legacy: false,
    locale: document.documentElement.lang || 'en',
    fallbackLocale: 'en',
    messages: { en },
});
