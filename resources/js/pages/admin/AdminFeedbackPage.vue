<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import PaginationBar from '@/components/PaginationBar.vue';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { describeDevice } from '@/lib/security';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/** The admin's Feedback inbox (Phase 8c): open first; mark handled or reopen. */
const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const TABS = ['open', 'handled', 'all'];

const status = ref('open');
const items = ref(null);
const meta = ref(null);

const when = (iso) => formatDateTime(iso, locale.value, auth.user?.timezone || 'UTC');
const browser = (agent) => {
    const { browser: name, system } = describeDevice(agent);

    return [name, system].filter(Boolean).join(' · ') || '—';
};
const openCount = computed(() => meta.value?.open ?? 0);

async function load(page = 1) {
    try {
        const { data } = await http.get('/admin/feedback', { params: { status: status.value, page } });
        items.value = data.data;
        meta.value = data.meta;
    } catch {
        toast.error(t('errors.generic'));
    }
}

async function setHandled(item, handled) {
    await http.patch(`/admin/feedback/${item.id}`, { handled });
    await load(meta.value?.current_page ?? 1);
}

function show(tab) {
    status.value = tab;
    load();
}

onMounted(() => load());
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('admin.feedback.title') }}</h1>
        <div class="sub">{{ t('admin.feedback.sub') }}</div>
    </div>

    <nav class="tabs" :aria-label="t('admin.feedback.title')">
        <button
            v-for="tab in TABS"
            :key="tab"
            type="button"
            :aria-current="status === tab ? 'page' : undefined"
            @click="show(tab)"
        >
            {{ t(`admin.feedback.${tab}`) }}
            <span v-if="tab === 'open' && openCount" class="count">{{ openCount }}</span>
        </button>
    </nav>

    <p v-if="items && items.length === 0" class="text-ink-3">{{ t('admin.feedback.empty') }}</p>
    <div v-else-if="items" class="space-y-3">
        <article v-for="item in items" :key="item.id" class="card">
            <div class="card-head">
                <span class="badge">{{ t(`feedback.categories.${item.category}`) }}</span>
                <span class="text-xs text-ink-3">{{ when(item.created_at) }}</span>
                <span class="right">
                    <button
                        type="button"
                        class="btn btn-sm"
                        :class="{ 'btn-primary': !item.handled_at }"
                        @click="setHandled(item, !item.handled_at)"
                    >
                        {{ item.handled_at ? t('admin.feedback.reopen') : t('admin.feedback.markHandled') }}
                    </button>
                </span>
            </div>
            <div class="card-body space-y-2">
                <p class="whitespace-pre-line">{{ item.message }}</p>
                <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-ink-3">
                    <div>
                        <dt class="inline">{{ t('admin.feedback.from') }}:</dt>
                        <dd class="ml-1 inline">
                            <RouterLink
                                v-if="item.user"
                                :to="{ name: 'admin.astrologer', params: { id: item.user.id } }"
                                class="hover:underline"
                                >{{ item.user.name }}</RouterLink
                            >
                            <span v-else class="italic">{{ t('admin.feedback.deletedAccount') }}</span>
                            <template v-if="item.practice"> · {{ item.practice.name }}</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="inline">{{ t('admin.feedback.page') }}:</dt>
                        <dd class="ml-1 inline font-mono">{{ item.page ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="inline">{{ t('admin.feedback.browser') }}:</dt>
                        <dd class="ml-1 inline">{{ browser(item.user_agent) }}</dd>
                    </div>
                    <div>
                        <dt class="inline">{{ t('admin.feedback.version') }}:</dt>
                        <dd class="ml-1 inline font-mono">{{ item.app_version ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </article>
        <div v-if="meta && meta.last_page > 1" class="card">
            <PaginationBar :meta="meta" @page="load" />
        </div>
    </div>
    <p v-else class="text-ink-3">{{ t('admin.common.loading') }}</p>
</template>
