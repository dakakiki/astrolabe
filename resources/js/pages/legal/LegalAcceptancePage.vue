<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import http from '@/lib/http';
import { formatLegalDate, pendingDocuments, versionsOf } from '@/lib/legal';
import { safeRedirect } from '@/router/guard';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * A new version of the Terms of Service or the Data Processing Agreement
 * (Phase 8c): the practice stays closed until the person accepts it. Someone
 * who does not agree can still export the practice and delete it (Settings →
 * Your data stays open) or sign out.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
const route = useRoute();
const router = useRouter();

const inForce = ref([]);
const agreed = ref(false);
const busy = ref(false);
const error = ref(null);

const documents = computed(() => {
    const pending = pendingDocuments(auth.user);

    return inForce.value.filter((document) => pending.includes(document.slug));
});

async function loadDocuments() {
    try {
        const { data } = await http.get('/legal');
        inForce.value = data.data;
    } catch {
        error.value = t('errors.generic');
    }
}

async function accept() {
    busy.value = true;
    error.value = null;

    try {
        const { data } = await http.post('/legal/acceptances', {
            documents: versionsOf(documents.value),
            accept: agreed.value,
        });
        auth.user = data.data;
        toast.success(t('legal.accept.done'));
        router.push(safeRedirect(route.query.redirect) ?? { name: 'dashboard' });
    } catch (failure) {
        // A version published while this page was open: show the new one.
        error.value = failure.response?.data?.errors?.documents?.[0] ?? t('errors.generic');
        agreed.value = false;
        await Promise.all([loadDocuments(), auth.load({ force: true })]);
    } finally {
        busy.value = false;
    }
}

async function signOut() {
    await auth.logout();
    router.push({ name: 'login' });
}

onMounted(loadDocuments);
</script>

<template>
    <div class="eyebrow">{{ t('legal.accept.eyebrow') }}</div>
    <h2>{{ t('legal.accept.title') }}</h2>
    <p class="sub">{{ t('legal.accept.intro') }}</p>

    <ul class="mb-5 space-y-3">
        <li v-for="document in documents" :key="document.slug" class="card">
            <div class="card-body">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <strong class="text-ink">{{ document.title }}</strong>
                    <span class="text-xs text-ink-3">{{ formatLegalDate(document.effective_on, locale) }}</span>
                </div>
                <p v-if="document.summary" class="mt-1 text-sm text-ink-2">{{ document.summary }}</p>
                <RouterLink
                    class="mt-1 inline-block text-sm"
                    :to="{ name: 'legal.show', params: { document: document.slug } }"
                    target="_blank"
                    >{{ t('legal.accept.read') }}</RouterLink
                >
            </div>
        </li>
    </ul>

    <p v-if="error" class="notice n-warn mb-4" role="alert">{{ error }}</p>

    <form novalidate @submit.prevent="accept">
        <label class="mb-4 flex items-start gap-2 text-sm">
            <input v-model="agreed" type="checkbox" class="mt-1" />
            <span>{{
                t('legal.accept.agree', { documents: documents.map((document) => document.title).join(', ') })
            }}</span>
        </label>
        <button class="btn btn-primary mb-5 w-full" type="submit" :disabled="!agreed || busy || !documents.length">
            {{ t('legal.accept.submit') }}
        </button>
    </form>

    <p class="mb-4 text-sm text-ink-3">
        {{ auth.isOwner ? t('legal.accept.leaveOwner') : t('legal.accept.leaveMember') }}
        <RouterLink v-if="auth.isOwner" :to="{ name: 'settings.data' }">{{ t('legal.accept.yourData') }}</RouterLink>
    </p>

    <button class="btn btn-ghost w-full" type="button" @click="signOut">{{ t('practiceDeletion.signOut') }}</button>
</template>
