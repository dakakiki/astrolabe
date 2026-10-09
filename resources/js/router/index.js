import { createRouter, createWebHistory } from 'vue-router';

import { useAuthStore } from '@/stores/auth';

import { resolveNavigation } from './guard';
import routes from './routes';

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    await auth.load();

    return resolveNavigation(to, { user: auth.user, workspace: auth.workspace });
});

export default router;
