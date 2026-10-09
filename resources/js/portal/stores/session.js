import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import http from '@/portal/http';

const GUEST = { state: 'guest', user: null, practices: [], current_practice_id: null };

/**
 * Who is signed in to the portal and which practices they can open
 * (`GET /session`; every sign-in answers with the same shape). Nothing of it
 * is stored in the browser — it is asked for again on every visit.
 */
export const useSessionStore = defineStore('portal-session', () => {
    const state = ref('guest');
    const user = ref(null);
    const practices = ref([]);
    const currentPracticeId = ref(null);
    const loaded = ref(false);
    // A practice a page shows before any is open (the invitation): its name, logo and colour.
    const shownPractice = ref(null);
    let pending = null;

    const practice = computed(() => practices.value.find((item) => item.id === currentPracticeId.value) ?? null);
    const brandedPractice = computed(() => practice.value ?? shownPractice.value);
    const isSignedIn = computed(() => user.value !== null);

    function apply(data) {
        state.value = data.state;
        user.value = data.user;
        practices.value = data.practices;
        currentPracticeId.value = data.current_practice_id;
        loaded.value = true;
    }

    function load({ force = false } = {}) {
        if (loaded.value && !force) return Promise.resolve();

        pending ??= http
            .get('/session', { skipAuthRedirect: true })
            .then((response) => apply(response.data.data))
            .finally(() => {
                pending = null;
            });

        return pending;
    }

    async function choose(id) {
        const response = await http.put('/practice', { practice_id: id });
        apply(response.data.data);
    }

    async function signOut() {
        try {
            await http.post('/sign-out', null, { skipAuthRedirect: true });
        } finally {
            clear();
        }
    }

    function clear() {
        apply(GUEST);
    }

    function setUser(data) {
        user.value = { ...user.value, ...data };
    }

    return {
        state,
        user,
        practices,
        currentPracticeId,
        loaded,
        shownPractice,
        practice,
        brandedPractice,
        isSignedIn,
        apply,
        load,
        choose,
        signOut,
        clear,
        setUser,
    };
});
