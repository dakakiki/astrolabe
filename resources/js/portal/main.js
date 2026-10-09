import { createApp } from 'vue';
import { createPinia } from 'pinia';

import PortalApp from '@/portal/PortalApp.vue';
import { setNoPracticeHandler, setUnauthenticatedHandler } from '@/portal/http';
import i18n from '@/portal/i18n';
import router from '@/portal/router';
import { useSessionStore } from '@/portal/stores/session';

// The client portal (docs/spec/12): its own small SPA on its own host. Nothing of
// the astrologers' application is loaded here.
const app = createApp(PortalApp).use(createPinia()).use(router).use(i18n);

const session = useSessionStore();

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

router.isReady().then(() => app.mount('#portal'));
