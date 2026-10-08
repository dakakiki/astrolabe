<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import PracticeExportCard from '@/components/PracticeExportCard.vue';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * The closing screen of a practice scheduled for deletion (Phase 8b): when it
 * goes, and — for the owner — cancelling and downloading an export. Every
 * other screen leads here until the deletion is cancelled or carried out.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
const router = useRouter();

const busy = ref(false);

const deletesOn = computed(() =>
    auth.workspace?.deletion
        ? formatDateTime(auth.workspace.deletion.deletes_at, locale.value, auth.user?.timezone || 'UTC', {
              dateStyle: 'long',
          })
        : '',
);

async function cancel() {
    busy.value = true;

    try {
        const { data } = await http.delete('/workspace/deletion');
        auth.workspace = data.data;
        toast.success(t('practiceDeletion.cancelled'));
        router.push({ name: 'dashboard' });
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        busy.value = false;
    }
}

async function signOut() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="eyebrow text-danger">{{ t('practiceDeletion.eyebrow') }}</div>
    <h2>{{ t('practiceDeletion.title', { practice: auth.workspace?.name, date: deletesOn }) }}</h2>
    <p class="sub">{{ t('practiceDeletion.intro') }}</p>

    <template v-if="auth.isOwner">
        <p class="mb-3">{{ t('practiceDeletion.ownerHint') }}</p>
        <button class="btn btn-primary mb-5 w-full" type="button" :disabled="busy" @click="cancel">
            {{ t('practiceDeletion.cancel') }}
        </button>
        <PracticeExportCard class="mb-5" />
    </template>
    <p v-else class="mb-5 text-ink-3">{{ t('practiceDeletion.memberHint') }}</p>

    <button class="btn btn-ghost w-full" type="button" @click="signOut">{{ t('practiceDeletion.signOut') }}</button>
</template>
