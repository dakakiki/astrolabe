<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { formatDateTime } from '@/lib/datetime';
import { formatBytes } from '@/lib/files';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * The practice export (Phase 8b; owner only): ask for one, wait while the queue
 * builds it (the list refreshes itself meanwhile), download it until it is
 * deleted. Also on the closing screen of a practice scheduled for deletion.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();

const exports = ref(null);
const busy = ref(false);
let timer = null;

const zone = computed(() => auth.user?.timezone || 'UTC');
const pending = computed(() => exports.value?.some((item) => item.status === 'pending') ?? false);
const linkHours = computed(() => reference.data?.retention?.export_link_hours ?? 24);

const when = (iso) => formatDateTime(iso, locale.value, zone.value);

async function load() {
    const { data } = await http.get('/workspace/exports');
    exports.value = data.data;

    clearTimeout(timer);

    if (pending.value) {
        timer = setTimeout(() => load().catch(() => {}), 3000);
    }
}

async function request() {
    busy.value = true;

    try {
        await http.post('/workspace/exports');
        toast.success(t('settings.data.export.requested'));
        await load();
    } catch (error) {
        toast.error(error.response?.status === 409 ? error.response.data.message : t('errors.generic'));
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    reference.load().catch(() => {});
    load().catch(() => toast.error(t('errors.generic')));
});
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.data.export.title') }}</h2>
        </div>
        <div class="card-body space-y-3">
            <p>{{ t('settings.data.export.intro') }}</p>
            <p class="text-ink-3">{{ t('settings.data.export.private') }}</p>

            <ul v-if="exports?.length" class="divide-y divide-line-soft rounded-sm border border-line">
                <li
                    v-for="item in exports"
                    :key="item.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5"
                >
                    <div class="min-w-0 flex-1">
                        <template v-if="item.status === 'ready'">
                            <div class="font-medium">
                                {{ t('settings.data.export.ready', { date: when(item.completed_at) }) }}
                                <span class="text-ink-3"> · {{ formatBytes(item.file_size, locale) }}</span>
                            </div>
                            <div class="text-ink-3">
                                {{ t('settings.data.export.until', { date: when(item.expires_at) }) }}
                            </div>
                        </template>
                        <div v-else-if="item.status === 'pending'" class="text-ink-2" role="status">
                            {{ t('settings.data.export.inProgress') }}
                        </div>
                        <div v-else class="text-danger">{{ t('settings.data.export.failed') }}</div>
                    </div>
                    <a
                        v-if="item.downloadable"
                        class="btn btn-sm"
                        :href="`/api/v1/workspace/exports/${item.id}/download`"
                        download
                        >{{ t('settings.data.export.download') }}</a
                    >
                </li>
            </ul>
            <p v-else-if="exports" class="text-ink-3">{{ t('settings.data.export.none') }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-primary" :disabled="busy || pending" @click="request">
                    {{ t('settings.data.export.request') }}
                </button>
                <span class="text-ink-3">{{ t('settings.data.export.emailHint', { hours: linkHours }) }}</span>
            </div>
        </div>
    </section>
</template>
