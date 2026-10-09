<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import PracticeMark from '@/portal/components/PracticeMark.vue';
import http from '@/portal/http';
import { readToken } from '@/portal/lib/portal';
import { useSessionStore } from '@/portal/stores/session';

/**
 * The page the invitation email opens (docs/spec/12, "Pozivnica", step 4): the
 * practice that invites, and "Accept and continue". Looking uses nothing up;
 * the button (a POST) accepts, activates the link and signs the person in.
 */
const { t } = useI18n();
const router = useRouter();
const session = useSessionStore();

const token = ref(null);
const invitation = ref(null);
const state = ref('loading');
const busy = ref(false);

onMounted(async () => {
    token.value = readToken(window.location.hash);
    history.replaceState(history.state, '', window.location.pathname);

    if (!token.value) {
        state.value = 'missing';

        return;
    }

    try {
        const response = await http.post('/invitations/preview', { token: token.value }, { skipAuthRedirect: true });
        invitation.value = response.data.data;
        state.value = invitation.value.status;
        session.shownPractice = invitation.value.practice;
    } catch {
        state.value = 'missing';
    }
});

onBeforeUnmount(() => {
    session.shownPractice = null;
});

async function accept() {
    busy.value = true;

    try {
        const response = await http.post('/invitations/accept', { token: token.value }, { skipAuthRedirect: true });
        session.apply(response.data.data);
        router.replace({ name: 'home' });
    } catch {
        state.value = 'failed';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <p v-if="state === 'loading'" class="text-ink-3">{{ t('common.loading') }}</p>

    <template v-else-if="invitation?.practice">
        <div class="mb-5 flex items-center gap-3">
            <PracticeMark :practice="invitation.practice" size="lg" />
            <div class="min-w-0">
                <div class="eyebrow">{{ t('portal.invitation.eyebrow') }}</div>
                <div class="font-serif text-xl break-words">{{ invitation.practice.name }}</div>
            </div>
        </div>

        <template v-if="state === 'valid'">
            <h1>{{ t('portal.invitation.title') }}</h1>
            <p class="sub">{{ t('portal.invitation.sub', { practice: invitation.practice.name }) }}</p>
            <div v-if="session.isSignedIn && !invitation.for_signed_in" class="notice n-info mb-4">
                {{ t('portal.invitation.switching', { email: session.user.email }) }}
            </div>
            <p class="mb-4 text-sm text-ink-3">{{ t('portal.invitation.forAddress', { email: invitation.email }) }}</p>
            <button type="button" class="btn btn-primary w-full justify-center" :disabled="busy" @click="accept">
                {{ t('portal.invitation.accept') }}
            </button>
            <p class="mt-4 text-xs text-ink-3">
                {{ t('portal.invitation.privacy', { practice: invitation.practice.name }) }}
            </p>
        </template>
        <template v-else>
            <h1>{{ t(`portal.invitation.states.${state}.title`) }}</h1>
            <p class="sub">{{ t(`portal.invitation.states.${state}.body`, { practice: invitation.practice.name }) }}</p>
            <RouterLink :to="{ name: 'sign-in' }" class="btn w-full justify-center">{{
                t('portal.invitation.signIn')
            }}</RouterLink>
        </template>
    </template>

    <template v-else>
        <h1>{{ t('portal.invitation.states.missing.title') }}</h1>
        <p class="sub">{{ t('portal.invitation.states.missing.body') }}</p>
        <RouterLink :to="{ name: 'sign-in' }" class="btn w-full justify-center">{{
            t('portal.invitation.signIn')
        }}</RouterLink>
    </template>
</template>
