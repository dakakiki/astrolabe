<script setup>
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import PracticeMark from '@/portal/components/PracticeMark.vue';
import { safeRedirect } from '@/portal/guard';
import { useSessionStore } from '@/portal/stores/session';

/**
 * One portal account, several practices (docs/spec/12, criterion 6): each is
 * opened on its own; the choice lasts for this session.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();

async function open(practice) {
    await session.choose(practice.id);
    router.replace(safeRedirect(route.query.redirect) ?? { name: 'home' });
}

async function signOut() {
    await session.signOut();
    router.replace({ name: 'sign-in' });
}
</script>

<template>
    <h1>{{ t('portal.choose.title') }}</h1>
    <p class="sub">{{ t('portal.choose.sub') }}</p>
    <div class="space-y-2">
        <button
            v-for="practice in session.practices"
            :key="practice.id"
            type="button"
            class="btn w-full justify-start gap-3 py-3"
            :aria-current="practice.id === session.currentPracticeId ? 'true' : undefined"
            @click="open(practice)"
        >
            <PracticeMark :practice="practice" />
            <span class="truncate">{{ practice.name }}</span>
            <span v-if="practice.id === session.currentPracticeId" class="ml-auto text-xs text-ink-3">{{
                t('portal.choose.current')
            }}</span>
        </button>
    </div>
    <p class="mt-6 text-sm">
        <button type="button" class="link" @click="signOut">{{ t('portal.nav.signOut') }}</button>
    </p>
</template>
