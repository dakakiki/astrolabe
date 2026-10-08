<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import AuditLogTable from '@/components/admin/AuditLogTable.vue';
import PaginationBar from '@/components/PaginationBar.vue';
import { cleanQuery } from '@/lib/admin';
import http from '@/lib/http';
import { useToastStore } from '@/stores/toast';

/**
 * The whole audit log for the operator (Phase 8c), newest first, with filters;
 * failed sign-ins and lockouts can be shown on their own.
 */
const { t } = useI18n();
const toast = useToastStore();

const EMPTY = { email: '', event: '', from: '', to: '', warnings: false };

const filters = reactive({ ...EMPTY });
const entries = ref(null);
const meta = ref(null);
const events = ref([]);
const months = ref(12);

async function load(page = 1) {
    try {
        const { data } = await http.get('/admin/audit-logs', {
            params: { ...cleanQuery({ ...filters, warnings: filters.warnings ? 1 : false }), page },
        });
        entries.value = data.data;
        meta.value = data.meta;
        events.value = data.events;
        months.value = data.retention_months;
    } catch (error) {
        toast.error(
            error.response?.status === 422 ? Object.values(error.response.data.errors)[0][0] : t('errors.generic'),
        );
    }
}

function clear() {
    Object.assign(filters, EMPTY);
    load();
}

onMounted(() => load());
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('admin.audit.title') }}</h1>
        <div class="sub">{{ t('admin.audit.sub', { months }) }}</div>
    </div>

    <section class="card">
        <form class="card-body flex flex-wrap items-end gap-2" @submit.prevent="load()">
            <label class="min-w-48 flex-1">
                <span class="mb-1 block text-xs text-ink-3">{{ t('admin.audit.filters.email') }}</span>
                <input v-model="filters.email" class="input" type="search" />
            </label>
            <label>
                <span class="mb-1 block text-xs text-ink-3">{{ t('admin.audit.filters.event') }}</span>
                <select v-model="filters.event" class="input w-auto">
                    <option value="">{{ t('admin.audit.filters.anyEvent') }}</option>
                    <option v-for="event in events" :key="event" :value="event">
                        {{ t(`admin.events.${event}`) }}
                    </option>
                </select>
            </label>
            <label>
                <span class="mb-1 block text-xs text-ink-3">{{ t('admin.audit.filters.from') }}</span>
                <input v-model="filters.from" class="input w-auto" type="date" />
            </label>
            <label>
                <span class="mb-1 block text-xs text-ink-3">{{ t('admin.audit.filters.to') }}</span>
                <input v-model="filters.to" class="input w-auto" type="date" />
            </label>
            <label class="flex items-center gap-2 pb-2 text-sm">
                <input v-model="filters.warnings" type="checkbox" />
                {{ t('admin.audit.filters.warnings') }}
            </label>
            <button type="submit" class="btn btn-primary">{{ t('admin.common.filter') }}</button>
            <button type="button" class="btn btn-ghost" @click="clear">{{ t('admin.common.clear') }}</button>
        </form>

        <p v-if="entries && entries.length === 0" class="card-body text-ink-3">{{ t('admin.audit.empty') }}</p>
        <AuditLogTable v-else-if="entries" :entries="entries" />
        <p v-else class="card-body text-ink-3">{{ t('admin.common.loading') }}</p>
        <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @page="load" />
    </section>
</template>
