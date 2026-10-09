<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import { home } from '@/portal/guard';
import http from '@/portal/http';
import { readToken } from '@/portal/lib/portal';
import { useSessionStore } from '@/portal/stores/session';

/**
 * The page the sign-in email opens. The token is after "#" and leaves the
 * address bar at once; it is used only when the person presses the button
 * (a POST), so a mail scanner opening the link uses nothing up.
 */
const { t } = useI18n();
const router = useRouter();
const session = useSessionStore();

const token = ref(null);
const busy = ref(false);
const failed = ref(false);

onMounted(() => {
    token.value = readToken(window.location.hash);
    history.replaceState(history.state, '', window.location.pathname);
});

async function signIn() {
    busy.value = true;

    try {
        await http.get('/session');
        const response = await http.post('/sign-in/link', { token: token.value }, { skipAuthRedirect: true });
        session.apply(response.data.data);
        router.replace(home(session));
    } catch {
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <template v-if="token && !failed">
        <h1>{{ t('portal.link.title') }}</h1>
        <p class="sub">{{ t('portal.link.sub') }}</p>
        <button type="button" class="btn btn-primary w-full justify-center" :disabled="busy" @click="signIn">
            {{ t('portal.link.action') }}
        </button>
    </template>
    <template v-else>
        <h1>{{ t('portal.link.invalidTitle') }}</h1>
        <p class="sub">{{ t('portal.link.invalid') }}</p>
        <RouterLink :to="{ name: 'sign-in' }" class="btn btn-primary w-full justify-center">{{
            t('portal.link.again')
        }}</RouterLink>
    </template>
</template>
