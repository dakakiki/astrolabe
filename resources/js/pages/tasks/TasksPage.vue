<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import TasksPanel from '@/components/TasksPanel.vue';

/**
 * The practice's tasks and follow-ups (docs/spec/02). The open tab lives in
 * the URL, so the dashboard can link straight to what is overdue.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const view = computed(() => route.query.show ?? 'open');

function show(next) {
    router.replace({ query: { ...route.query, show: next === 'open' ? undefined : next } });
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('tasks.eyebrow') }}</div>
        <h1>{{ t('tasks.title') }}</h1>
        <div class="sub">{{ t('tasks.sub') }}</div>
    </div>

    <TasksPanel :view="view" @update:view="show" />
</template>
