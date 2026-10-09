import { createRouter, createWebHistory } from 'vue-router';

import { decide } from '@/portal/guard';
import routes from '@/portal/routes';
import { useSessionStore } from '@/portal/stores/session';

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

// The session is asked for once per visit, then kept in memory only.
router.beforeEach(async (to) => {
    const session = useSessionStore();

    if (!to.meta.public || session.loaded === false) {
        try {
            await session.load();
        } catch {
            session.clear();
        }
    }

    return decide(to, session);
});

export default router;
