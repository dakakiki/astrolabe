import { createApp } from 'vue';
import { createPinia } from 'pinia';

import PortalApp from '@/portal/PortalApp.vue';
import { setNoPracticeHandler, setUnauthenticatedHandler } from '@/portal/http';
import i18n from '@/portal/i18n';
import { isChunkLoadError } from '@/portal/lib/pwa';
import { captureInstallPrompt } from '@/portal/pwa/install';
import { registerServiceWorker } from '@/portal/pwa/serviceWorker';
import router from '@/portal/router';
import { usePwaStore } from '@/portal/stores/pwa';
import { useSessionStore } from '@/portal/stores/session';

// The client portal (docs/spec/12): its own small SPA on its own host. Nothing of
// the astrologers' application is loaded here.

// Phase 9c: the browser may say the portal can be installed right after loading — listen first.
captureInstallPrompt();

const app = createApp(PortalApp).use(createPinia()).use(router).use(i18n);

const session = useSessionStore();
const pwa = usePwaStore();

// The session ended elsewhere (sign-out, "sign out everywhere", expiry).
setUnauthenticatedHandler(() => {
    if (!session.isSignedIn) return;

    session.clear();
    router.push({ name: 'sign-in' });
});

// The open practice closed meanwhile (access revoked, client archived), or none is chosen.
setNoPracticeHandler(async () => {
    await session.load({ force: true });
    router.push(session.state === 'choose' ? { name: 'choose' } : { name: 'no-access' });
});

// A screen's file is gone (a new version replaced it) or did not arrive: offer "Reload".
router.onError((error) => {
    if (isChunkLoadError(error)) pwa.markStale();
});

// Mounted even when the first screen's file failed, so "Reload" is there to press.
router.isReady().then(
    () => app.mount('#portal'),
    (error) => {
        if (!isChunkLoadError(error)) throw error;

        app.mount('#portal');
    },
);

// A built portal only (see pwa/serviceWorker.js); nothing happens on the dev server.
registerServiceWorker();
