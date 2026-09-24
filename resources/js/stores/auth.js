import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import http, { ensureCsrfCookie } from '@/lib/http';

/**
 * The signed-in user and the workspace they act in, as the server reports
 * them. Nothing here is persisted in the browser.
 */
export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const workspace = ref(null);
    const loaded = ref(false);

    const isAuthenticated = computed(() => user.value !== null);
    const isVerified = computed(() => user.value?.email_verified === true);
    const isOwner = computed(() => workspace.value?.role === 'owner');

    async function load({ force = false } = {}) {
        if (loaded.value && !force) {
            return;
        }

        try {
            const { data } = await http.get('/me', { skipAuthRedirect: true });
            user.value = data.data.user;
            workspace.value = data.data.workspace;
        } catch (error) {
            if (error.response?.status !== 401) {
                throw error;
            }
            clear();
        } finally {
            loaded.value = true;
        }
    }

    async function login(credentials) {
        await ensureCsrfCookie();
        await http.post('/auth/login', credentials);
        await load({ force: true });
    }

    async function register(payload) {
        await ensureCsrfCookie();
        await http.post('/auth/register', payload);
        await load({ force: true });
    }

    async function logout() {
        try {
            await http.post('/auth/logout', null, { skipAuthRedirect: true });
        } finally {
            clear();
        }
    }

    function clear() {
        user.value = null;
        workspace.value = null;
    }

    return {
        user,
        workspace,
        loaded,
        isAuthenticated,
        isVerified,
        isOwner,
        load,
        login,
        register,
        logout,
        clear,
    };
});
