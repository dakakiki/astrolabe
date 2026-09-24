<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import PaginationBar from '@/components/PaginationBar.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useLabels } from '@/composables/useLabels';
import { formatDate, formatRelative, initials } from '@/lib/format';
import http from '@/lib/http';

const { t, locale } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();

const clients = ref([]);
const meta = ref(null);
const loading = ref(true);
const tags = ref([]);
const methods = ref([]);

// Filters live in the URL, so back/forward and shared links keep them.
const filters = computed(() => ({
    search: route.query.search ?? '',
    status: route.query.status ?? '',
    tag: route.query.tag ?? '',
    method: route.query.method ?? '',
    activity: route.query.activity ?? '',
    sort: route.query.sort ?? 'name',
    page: Number(route.query.page ?? 1),
}));

const hasFilters = computed(() =>
    ['search', 'status', 'tag', 'method', 'activity'].some((key) => filters.value[key] !== ''),
);

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
        const { data } = await http.get('/clients', { params: { ...params, per_page: 25 } });
        clients.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}

watch(() => route.query, load);

onMounted(async () => {
    load();
    const [tagResponse, methodResponse] = await Promise.all([http.get('/tags'), http.get('/astrology-methods')]);
    tags.value = tagResponse.data.data;
    methods.value = methodResponse.data.data.filter((method) => method.selected);
});

function born(client) {
    const birth = client.birth;
    if (!birth) return '';

    return [formatDate(birth.birth_date, locale.value, 'medium'), birth.birth_place].filter(Boolean).join(' · ');
}
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div>
            <div class="eyebrow">{{ t('clients.eyebrow') }}</div>
            <h1>{{ t('clients.title') }}</h1>
            <div class="sub">{{ t('clients.sub') }}</div>
        </div>
        <RouterLink :to="{ name: 'clients.create' }" class="btn btn-primary ml-auto"
            >+ {{ t('clients.add') }}</RouterLink
        >
    </div>

    <section class="card">
        <div class="flex flex-wrap items-center gap-2 border-b border-line-soft p-3">
            <input
                v-model="searchDraft"
                class="input max-w-xs flex-1"
                type="search"
                :placeholder="t('clients.filters.search')"
                :aria-label="t('clients.filters.search')"
            />
            <select
                class="input w-auto"
                :aria-label="t('clients.filters.status')"
                :value="filters.status"
                @change="setFilter('status', $event.target.value)"
            >
                <option value="">{{ t('clients.filters.statusDefault') }}</option>
                <option v-for="status in ['lead', 'active', 'inactive', 'archived']" :key="status" :value="status">
                    {{ labels.status(status) }}
                </option>
                <option value="all">{{ t('clients.filters.statusAll') }}</option>
            </select>
            <select
                v-if="tags.length"
                class="input w-auto"
                :aria-label="t('clients.filters.tag')"
                :value="filters.tag"
                @change="setFilter('tag', $event.target.value)"
            >
                <option value="">{{ t('clients.filters.tag') }}: {{ t('clients.filters.any') }}</option>
                <option v-for="tag in tags" :key="tag.id" :value="tag.name">
                    {{ tag.name }} ({{ tag.clients_count }})
                </option>
            </select>
            <select
                v-if="methods.length"
                class="input w-auto"
                :aria-label="t('clients.filters.method')"
                :value="filters.method"
                @change="setFilter('method', $event.target.value)"
            >
                <option value="">{{ t('clients.filters.method') }}: {{ t('clients.filters.any') }}</option>
                <option v-for="method in methods" :key="method.id" :value="String(method.id)">
                    {{ labels.method(method) }}
                </option>
            </select>
            <select
                class="input w-auto"
                :aria-label="t('clients.filters.activity')"
                :value="filters.activity"
                @change="setFilter('activity', $event.target.value)"
            >
                <option value="">{{ t('clients.filters.activity') }}: {{ t('clients.filters.any') }}</option>
                <option value="week">{{ t('clients.filters.activityWeek') }}</option>
                <option value="month">{{ t('clients.filters.activityMonth') }}</option>
                <option value="quarter">{{ t('clients.filters.activityQuarter') }}</option>
                <option value="older">{{ t('clients.filters.activityOlder') }}</option>
            </select>
            <select
                class="input ml-auto w-auto"
                :aria-label="t('clients.filters.sort')"
                :value="filters.sort"
                @change="setFilter('sort', $event.target.value === 'name' ? '' : $event.target.value)"
            >
                <option value="name">{{ t('clients.filters.sortName') }}</option>
                <option value="-last_activity_at">{{ t('clients.filters.sortActivity') }}</option>
                <option value="-created_at">{{ t('clients.filters.sortCreated') }}</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table v-if="clients.length" class="data" :aria-busy="loading">
                <thead>
                    <tr>
                        <th>{{ t('clients.columns.client') }}</th>
                        <th>{{ t('clients.columns.status') }}</th>
                        <th>{{ t('clients.columns.born') }}</th>
                        <th>{{ t('clients.columns.methods') }}</th>
                        <th>{{ t('clients.columns.tags') }}</th>
                        <th>{{ t('clients.columns.activity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="client in clients"
                        :key="client.id"
                        @click="router.push({ name: 'clients.show', params: { id: client.id } })"
                    >
                        <td>
                            <div class="person">
                                <span class="ini" aria-hidden="true">{{ initials(client.full_name) }}</span>
                                <div class="min-w-0">
                                    <RouterLink
                                        :to="{ name: 'clients.show', params: { id: client.id } }"
                                        class="nm hover:underline"
                                        @click.stop
                                        >{{ client.full_name }}</RouterLink
                                    >
                                    <div class="meta truncate">{{ client.email }}</div>
                                </div>
                            </div>
                        </td>
                        <td><StatusBadge :status="client.status" /></td>
                        <td>
                            <span class="text-ink-2">{{ born(client) }}</span>
                            <span
                                v-if="client.birth && !client.birth.chart.ready"
                                class="ml-1.5 text-warn"
                                :title="t('clients.chartIncomplete')"
                                :aria-label="t('clients.chartIncomplete')"
                                >⚠</span
                            >
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="method in client.methods" :key="method.id" class="method-pill">{{
                                    labels.method(method)
                                }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="tag in client.tags" :key="tag" class="tag">{{ tag }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-ink-3">
                            {{ formatRelative(client.last_activity_at, locale) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="!loading" class="p-8 text-center text-ink-3">
                {{ hasFilters ? t('clients.noMatches') : t('clients.empty') }}
            </p>
            <p v-else class="p-8 text-center text-ink-3">{{ t('common.loading') }}</p>
        </div>

        <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @page="setFilter('page', $event)" />
    </section>
</template>
