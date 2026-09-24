<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import ConsultationsTable from '@/components/ConsultationsTable.vue';
import PaginationBar from '@/components/PaginationBar.vue';
import http from '@/lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const consultations = ref([]);
const meta = ref(null);
const loading = ref(true);

const STATUSES = ['draft', 'scheduled', 'completed', 'cancelled', 'no_show'];

// Filters live in the URL, so back/forward and shared links keep them.
const filters = computed(() => ({
    search: route.query.search ?? '',
    status: route.query.status ?? '',
    from: route.query.from ?? '',
    to: route.query.to ?? '',
    page: Number(route.query.page ?? 1),
}));

const hasFilters = computed(() => ['search', 'status', 'from', 'to'].some((key) => filters.value[key] !== ''));

function setFilter(key, value) {
    const query = { ...route.query, [key]: value || undefined };
    if (key !== 'page') delete query.page;
    router.replace({ query });
}

let searchTimer;
const searchDraft = ref(filters.value.search);
watch(searchDraft, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => setFilter('search', value.trim()), 300);
});

async function load() {
    loading.value = true;
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value !== ''));

    try {
        const { data } = await http.get('/consultations', { params: { ...params, per_page: 25 } });
        consultations.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}

watch(() => route.query, load);
onMounted(load);

function clear() {
    searchDraft.value = '';
    router.replace({ query: {} });
}
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div>
            <div class="eyebrow">{{ t('consultations.eyebrow') }}</div>
            <h1>{{ t('consultations.title') }}</h1>
            <div class="sub">{{ t('consultations.sub') }}</div>
        </div>
        <RouterLink :to="{ name: 'consultations.create' }" class="btn btn-primary ml-auto"
            >+ {{ t('consultations.add') }}</RouterLink
        >
    </div>

    <section class="card">
        <div class="flex flex-wrap items-center gap-2 border-b border-line-soft p-3">
            <input
                v-model="searchDraft"
                class="input max-w-xs flex-1"
                type="search"
                :placeholder="t('consultations.filters.search')"
                :aria-label="t('consultations.filters.search')"
            />
            <select
                class="input w-auto"
                :aria-label="t('consultations.filters.status')"
                :value="filters.status"
                @change="setFilter('status', $event.target.value)"
            >
                <option value="">{{ t('consultations.filters.anyStatus') }}</option>
                <option v-for="status in STATUSES" :key="status" :value="status">
                    {{ t(`consultationStatuses.${status}`) }}
                </option>
            </select>
            <label class="flex items-center gap-1.5 text-xs text-ink-3">
                {{ t('consultations.filters.from') }}
                <input
                    class="input w-auto"
                    type="date"
                    :value="filters.from"
                    @change="setFilter('from', $event.target.value)"
                />
            </label>
            <label class="flex items-center gap-1.5 text-xs text-ink-3">
                {{ t('consultations.filters.to') }}
                <input
                    class="input w-auto"
                    type="date"
                    :value="filters.to"
                    @change="setFilter('to', $event.target.value)"
                />
            </label>
            <button v-if="hasFilters" type="button" class="btn btn-ghost btn-sm" @click="clear">
                {{ t('consultations.filters.clear') }}
            </button>
        </div>

        <div class="overflow-x-auto">
            <ConsultationsTable v-if="consultations.length" :consultations="consultations" :busy="loading" />
            <p v-else-if="!loading" class="empty">
                {{ hasFilters ? t('consultations.noMatches') : t('consultations.empty') }}
            </p>
            <p v-else class="p-8 text-center text-ink-3">{{ t('common.loading') }}</p>
        </div>

        <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @page="setFilter('page', $event)" />
    </section>
</template>
