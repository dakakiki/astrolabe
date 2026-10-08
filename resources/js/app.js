import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';

import App from './App.vue';
import router from './router';
import i18n, { setLocale } from './i18n';
import { setPracticeClosedHandler, setUnauthenticatedHandler } from './lib/http';
import { useAuthStore } from './stores/auth';
import { useToastStore } from './stores/toast';

const app = createApp(App).use(createPinia()).use(router).use(i18n);

const auth = useAuthStore();

// The interface follows the signed-in user's language.
watch(() => auth.user?.locale, setLocale, { immediate: true });

// A 401 from any API call means the session ended elsewhere (sign-out, expiry).
setUnauthenticatedHandler(() => {
    if (!auth.isAuthenticated) {
        return;
    }
    auth.clear();
    useToastStore().error(i18n.global.t('errors.sessionExpired'));
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
});

// The owner scheduled the practice for deletion in another tab or on another device.
setPracticeClosedHandler(async () => {
    await auth.load({ force: true });
    router.push({ name: 'practice-deletion' });
});

// Mount once the first route (and the session check in its guard) has resolved,
// so the wrong layout never flashes.
router.isReady().then(() => app.mount('#app'));
