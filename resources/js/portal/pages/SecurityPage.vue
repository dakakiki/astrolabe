<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import { describeDevice } from '@/lib/security';
import http from '@/portal/http';
import { displayZone } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';

/**
 * Portal → Security (docs/spec/12): where the person is signed in, and
 * "Sign out everywhere" — this device too. There is no password to change.
 */
const { t, locale } = useI18n();
const router = useRouter();
const session = useSessionStore();

const sessions = ref(null);
const busy = ref(false);

onMounted(() =>
    loadScreen(async () => {
        const response = await http.get('/sessions');
        sessions.value = response.data.data;
    }),
);

function device(entry) {
    const { browser, system } = describeDevice(entry.user_agent);

    if (browser && system) return t('portal.security.device', { browser, system });

    return browser ?? system ?? t('portal.security.unknownDevice');
}

function when(iso) {
    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: displayZone(session.user, session.practice),
    }).format(new Date(iso));
}

async function signOutEverywhere() {
    if (!window.confirm(t('portal.security.confirm'))) return;

    busy.value = true;

    try {
        await http.delete('/sessions', { skipAuthRedirect: true });
    } finally {
        session.clear();
        router.replace({ name: 'sign-in' });
    }
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name ?? t('portal.title') }}</div>
        <h1>{{ t('portal.nav.security') }}</h1>
        <div class="sub">{{ t('portal.security.sub', { email: session.user?.email }) }}</div>
    </div>

    <section class="card max-w-xl">
        <div class="card-head">
            <h2>{{ t('portal.security.sessions') }}</h2>
        </div>
        <p v-if="sessions === null" class="card-body text-ink-3">{{ t('common.loading') }}</p>
        <div v-else class="portal-list">
            <div
                v-for="(entry, index) in sessions"
                :key="index"
                class="portal-item flex flex-wrap items-baseline gap-x-3"
            >
                <span class="font-medium">{{ device(entry) }}</span>
                <span v-if="entry.current" class="badge b-ok">{{ t('portal.security.thisDevice') }}</span>
                <span class="w-full text-xs text-ink-3">{{
                    t('portal.security.lastActive', { when: when(entry.last_active_at) })
                }}</span>
            </div>
        </div>
        <div class="card-body border-t border-line-soft">
            <p class="mb-3 text-sm text-ink-3">{{ t('portal.security.everywhereHint') }}</p>
            <button type="button" class="btn btn-danger" :disabled="busy" @click="signOutEverywhere">
                {{ t('portal.security.everywhere') }}
            </button>
        </div>
    </section>
</template>
