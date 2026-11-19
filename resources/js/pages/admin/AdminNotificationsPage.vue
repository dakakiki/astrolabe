<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';

import AppIcon from '@/components/AppIcon.vue';
import http from '@/lib/http';

/**
 * Admin → Notifications (docs/spec/09, "Obaveštenja — tekstovi"): every notification the
 * app sends, to word in the operator's own words — the emails by whom they are for, the
 * client's push messages, and the layout around every email. A row opens its editor.
 */
const { t } = useI18n();
const route = useRoute();

const TABS = ['email', 'push', 'layout'];
const GROUPS = ['astrologer', 'client', 'operator'];

const messages = ref(null);
const failed = ref(false);

const tab = computed(() => (TABS.includes(route.query.tab) ? route.query.tab : 'email'));
const emails = computed(() =>
    GROUPS.map((group) => ({
        group,
        items: (messages.value ?? []).filter((item) => item.channel === 'mail' && item.group === group),
    })),
);
const pushes = computed(() => (messages.value ?? []).filter((item) => item.channel === 'push'));
const layout = computed(() => (messages.value ?? []).find((item) => item.id === 'layout') ?? null);

onMounted(async () => {
    try {
        const { data } = await http.get('/admin/notifications');
        messages.value = data.data;
    } catch {
        failed.value = true;
    }
});

const tabQuery = (name) => ({ ...route.query, tab: name === 'email' ? undefined : name });
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('admin.notifications.title') }}</h1>
        <div class="sub">{{ t('admin.notifications.sub') }}</div>
    </div>

    <nav class="tabs" :aria-label="t('admin.notifications.title')">
        <RouterLink
            v-for="name in TABS"
            :key="name"
            :to="{ query: tabQuery(name) }"
            :aria-current="tab === name ? 'page' : undefined"
            replace
        >
            {{ t(`admin.notifications.tabs.${name}`) }}
        </RouterLink>
    </nav>

    <p v-if="failed" class="notice n-warn" role="alert">{{ t('errors.generic') }}</p>
    <p v-else-if="!messages" class="text-ink-3" role="status">{{ t('common.loading') }}</p>

    <template v-else-if="tab === 'email'">
        <section v-for="block in emails" :key="block.group" class="card mb-4">
            <div class="card-head">
                <h2>{{ t(`admin.notifications.groups.${block.group}`) }}</h2>
                <span class="right font-mono text-ink-3">{{ block.items.length }}</span>
            </div>
            <ul class="divide-y divide-line-soft">
                <li v-for="item in block.items" :key="item.id">
                    <RouterLink :to="{ name: 'admin.notification', params: { id: item.id } }" class="notice-row">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-ink">{{ item.label }}</span>
                            <span class="block text-xs text-ink-3">{{ item.when }}</span>
                            <span class="block truncate font-mono text-xs text-ink-2">{{ item.subject }}</span>
                        </span>
                        <span v-if="item.worded" class="badge b-info">{{
                            t('admin.notifications.worded', { count: item.worded }, item.worded)
                        }}</span>
                        <AppIcon name="continue" class="text-ink-3" />
                    </RouterLink>
                </li>
            </ul>
        </section>
    </template>

    <section v-else-if="tab === 'push'" class="card">
        <div class="card-head">
            <h2>{{ t('admin.notifications.pushTitle') }}</h2>
            <span class="right font-mono text-ink-3">{{ pushes.length }}</span>
        </div>
        <p class="border-b border-line-soft px-4 py-2 text-xs text-ink-3">{{ t('admin.notifications.pushHint') }}</p>
        <ul class="divide-y divide-line-soft">
            <li v-for="item in pushes" :key="item.id">
                <RouterLink :to="{ name: 'admin.notification', params: { id: item.id } }" class="notice-row">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-ink">{{ item.label }}</span>
                        <span class="block text-xs text-ink-3">{{ item.when }}</span>
                        <span class="block truncate font-mono text-xs text-ink-2"
                            >{{ item.subject }} · {{ item.body }}</span
                        >
                    </span>
                    <span v-if="item.worded" class="badge b-info">{{
                        t('admin.notifications.worded', { count: item.worded }, item.worded)
                    }}</span>
                    <AppIcon name="continue" class="text-ink-3" />
                </RouterLink>
            </li>
        </ul>
    </section>

    <section v-else-if="layout" class="card">
        <div class="card-head">
            <h2>{{ layout.label }}</h2>
        </div>
        <div class="card-body">
            <p class="mb-3 text-ink-2">{{ t('admin.notifications.layoutHint') }}</p>
            <RouterLink
                :to="{ name: 'admin.notification', params: { id: 'layout' } }"
                class="btn btn-primary"
                v-tip="t('tips.admin.notifications.editLayout')"
                ><AppIcon name="edit" />{{ t('admin.notifications.editLayout') }}</RouterLink
            >
            <span v-if="layout.worded" class="badge b-info ml-2">{{
                t('admin.notifications.worded', { count: layout.worded }, layout.worded)
            }}</span>
        </div>
    </section>
</template>
