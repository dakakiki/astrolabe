<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import { useSessionStore } from '@/portal/stores/session';

/**
 * Signed in, but no practice opens now: the access was revoked, the client
 * archived, or the practice is closing (docs/spec/12, criterion 4). The page
 * does not say which — that is between the client and the practice.
 */
const { t } = useI18n();
const router = useRouter();
const session = useSessionStore();

async function signOut() {
    await session.signOut();
    router.replace({ name: 'sign-in' });
}
</script>

<template>
    <h1>{{ t('portal.noAccess.title') }}</h1>
    <p class="sub">{{ t('portal.noAccess.body') }}</p>
    <div class="flex flex-wrap gap-2">
        <RouterLink :to="{ name: 'security' }" class="btn">{{ t('portal.nav.security') }}</RouterLink>
        <button type="button" class="btn btn-primary" @click="signOut">{{ t('portal.nav.signOut') }}</button>
    </div>
</template>
