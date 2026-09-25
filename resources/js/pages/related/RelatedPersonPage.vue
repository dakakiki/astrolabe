<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import BirthDetailsCard from '@/components/BirthDetailsCard.vue';
import NatalChart from '@/components/NatalChart.vue';
import TransitsPanel from '@/components/TransitsPanel.vue';
import { useLabels } from '@/composables/useLabels';
import { initials } from '@/lib/format';
import http from '@/lib/http';
import { synastryRoute } from '@/lib/synastry';
import { useToastStore } from '@/stores/toast';

/**
 * A related person (docs/spec/02, "Povezane osobe"): who they are to which
 * clients, their birth data and their own natal chart, and the transits to
 * it; their chart compared with each client's opens on that client's
 * Synastry tab. From here the person can become a client of their own, with
 * nothing entered again.
 */
const { t } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const person = ref(null);
const notFound = ref(false);
const chart = ref(null);
const chartState = ref('idle');
const switchingHouses = ref(false);
const busy = ref(false);

const TABS = ['chart', 'transits'];
const tab = computed(() => (route.query.tab === 'transits' ? 'transits' : 'chart'));
const transitMoment = computed(() => (typeof route.query.at === 'string' ? route.query.at : ''));

function setTransitMoment(at) {
    router.replace({ query: { ...route.query, at: at || undefined } });
}

async function load() {
    person.value = null;
    notFound.value = false;
    chart.value = null;
    chartState.value = 'idle';

    try {
        const { data } = await http.get(`/related-people/${route.params.id}`);
        person.value = data.data;
    } catch (error) {
        if (error.response?.status === 404) notFound.value = true;
        else throw error;
    }

    if (person.value?.birth?.chart.ready) loadChart();
}

async function loadChart(houseSystem = null) {
    if (houseSystem) switchingHouses.value = true;
    else chartState.value = 'loading';

    try {
        const { data } = await http.get(`/related-people/${person.value.id}/chart`, {
            params: houseSystem ? { house_system: houseSystem } : {},
        });
        if (data.data.status === 'ready') chart.value = data.data;
        chartState.value = 'done';
    } catch {
        if (houseSystem) toast.error(t('chart.unavailable'));
        else chartState.value = 'failed';
    } finally {
        switchingHouses.value = false;
    }
}

onMounted(load);
watch(
    () => route.params.id,
    (next) => next && load(),
);

// Where to go when the person is gone: the first client they belonged with.
const firstClientRoute = () => {
    const client = person.value.relationships[0]?.client;
    return client
        ? { name: 'clients.show', params: { id: client.id }, query: { tab: 'related' } }
        : { name: 'clients.index' };
};

async function makeClient() {
    if (!window.confirm(t('related.person.confirmMakeClient', { name: person.value.full_name }))) return;

    busy.value = true;
    try {
        const { data } = await http.post(`/related-people/${person.value.id}/convert`);
        toast.success(t('related.person.converted', { name: data.data.full_name }));
        router.push({ name: 'clients.show', params: { id: data.data.id } });
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (!window.confirm(t('related.person.confirmDelete', { name: person.value.full_name }))) return;

    const back = firstClientRoute();
    await http.delete(`/related-people/${person.value.id}`);
    toast.success(t('related.person.deleted'));
    router.push(back);
}
</script>

<template>
    <p v-if="notFound" class="text-ink-3">{{ t('notFound.title') }}</p>

    <template v-else-if="person">
        <div class="page-head flex flex-wrap items-center gap-4">
            <div class="person">
                <span class="ini size-12! basis-12! text-base!" aria-hidden="true">{{
                    initials(person.full_name)
                }}</span>
                <div>
                    <div class="eyebrow">{{ t('related.person.eyebrow') }}</div>
                    <h1>{{ person.full_name }}</h1>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span v-for="link in person.relationships" :key="link.id">
                            {{ t('related.person.of', { relationship: labels.relationship(link.relationship_type) }) }}
                            <RouterLink
                                :to="{
                                    name: 'clients.show',
                                    params: { id: link.client.id },
                                    query: { tab: 'related' },
                                }"
                                class="font-medium hover:underline"
                                >{{ link.client.full_name }}</RouterLink
                            >
                        </span>
                    </div>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap gap-2">
                <RouterLink :to="{ name: 'related-people.edit', params: { id: person.id } }" class="btn">{{
                    t('related.person.edit')
                }}</RouterLink>
                <button type="button" class="btn btn-ghost" @click="remove">{{ t('related.person.delete') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy" @click="makeClient">
                    {{ t('related.person.makeClient') }}
                </button>
            </div>
        </div>

        <nav class="tabs" :aria-label="person.full_name">
            <RouterLink
                v-for="name in TABS"
                :key="name"
                :to="{ query: { tab: name === 'chart' ? undefined : name } }"
                :aria-current="tab === name ? 'page' : undefined"
                replace
            >
                {{ t(`clients.profile.tabs.${name}`) }}
            </RouterLink>
        </nav>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
            <TransitsPanel
                v-if="tab === 'transits'"
                class="min-w-0"
                :endpoint="`/related-people/${person.id}/transits`"
                :name="person.full_name"
                :at="transitMoment"
                :edit-to="{ name: 'related-people.edit', params: { id: person.id } }"
                @update:at="setTransitMoment"
            />

            <div v-else class="min-w-0 space-y-4">
                <BirthDetailsCard
                    :birth="person.birth"
                    :edit-to="{ name: 'related-people.edit', params: { id: person.id } }"
                />

                <NatalChart
                    v-if="chart"
                    :chart="chart"
                    :name="person.full_name"
                    selectable
                    :busy="switchingHouses"
                    @house-system="loadChart"
                />
                <section v-else-if="chartState !== 'idle'" class="card">
                    <div class="card-head">
                        <h2>{{ t('chart.title') }}</h2>
                    </div>
                    <div class="card-body">
                        <p v-if="chartState === 'loading'" class="text-ink-3" role="status">{{ t('chart.loading') }}</p>
                        <div v-else-if="chartState === 'failed'" class="notice n-warn" role="alert">
                            {{ t('chart.unavailable') }}
                        </div>
                    </div>
                </section>
            </div>

            <div class="space-y-4">
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('related.person.contact') }}</h2>
                    </div>
                    <dl v-if="person.email || person.phone" class="card-body facts">
                        <template v-if="person.email">
                            <dt>{{ t('clients.form.email') }}</dt>
                            <dd class="truncate">
                                <a :href="`mailto:${person.email}`">{{ person.email }}</a>
                            </dd>
                        </template>
                        <template v-if="person.phone">
                            <dt>{{ t('clients.form.phone') }}</dt>
                            <dd>
                                <a :href="`tel:${person.phone}`">{{ person.phone }}</a>
                            </dd>
                        </template>
                    </dl>
                    <p v-else class="card-body text-ink-3">{{ t('related.person.noContact') }}</p>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('related.person.belongsWith') }}</h2>
                    </div>
                    <ul class="card-body space-y-3">
                        <li v-for="link in person.relationships" :key="link.id">
                            <RouterLink
                                :to="{
                                    name: 'clients.show',
                                    params: { id: link.client.id },
                                    query: { tab: 'related' },
                                }"
                                class="font-medium hover:underline"
                                >{{ link.client.full_name }}</RouterLink
                            >
                            <span class="tag ml-2">{{ labels.relationship(link.relationship_type) }}</span>
                            <p v-if="link.notes" class="text-xs text-ink-3">{{ link.notes }}</p>
                            <RouterLink
                                v-if="person.birth?.chart.ready"
                                :to="synastryRoute(link.client.id, 'person', person.id)"
                                class="btn btn-sm mt-1.5"
                                >{{ t('related.person.compareWith', { name: link.client.full_name }) }}</RouterLink
                            >
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </template>

    <p v-else class="text-ink-3">{{ t('common.loading') }}</p>
</template>
