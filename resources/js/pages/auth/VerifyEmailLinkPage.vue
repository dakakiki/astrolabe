<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
const route = useRoute();
const router = useRouter();

const state = ref('checking');

// The emailed link carries the signed API URL's path parameters and query;
// the page replays them against the API with the user's session.
onMounted(async () => {
    const { id, hash } = route.params;
    const query = new URLSearchParams({
        expires: route.query.expires ?? '',
        signature: route.query.signature ?? '',
    });

    try {
        await http.get(`/auth/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${query}`);
        await auth.load({ force: true });
        toast.success(t('auth.verify.done'));
        router.replace({ name: 'dashboard' });
    } catch {
        state.value = 'failed';
    }
});
</script>

<template>
    <h2>{{ t('auth.verify.title') }}</h2>

    <p v-if="state === 'checking'" class="text-ink-3" role="status">{{ t('auth.verify.checking') }}</p>

    <template v-else>
        <div class="notice n-danger mb-4" role="alert">
            <div>
                <strong>{{ t('auth.verify.failed') }}</strong>
                {{ t('auth.verify.failedHint') }}
            </div>
        </div>
        <RouterLink :to="{ name: 'verify-email' }" class="btn w-full">{{ t('auth.verify.resend') }}</RouterLink>
    </template>
</template>
