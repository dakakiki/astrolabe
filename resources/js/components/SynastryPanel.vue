<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import AspectsTable from '@/components/AspectsTable.vue';
import ChartWheel from '@/components/ChartWheel.vue';
import ClientPicker from '@/components/ClientPicker.vue';
import HouseCuspsTable from '@/components/HouseCuspsTable.vue';
import PositionsTable from '@/components/PositionsTable.vue';
import SynastryContactsTable from '@/components/SynastryContactsTable.vue';
import { useLabels } from '@/composables/useLabels';
import { ASPECT_ORDER } from '@/lib/chart';
import http from '@/lib/http';
import {
    comparisonOptions,
    outerPoints,
    overlayChart,
    parseWith,
    personalContacts,
    synastryLines,
    synastryQuery,
    withValue,
} from '@/lib/synastry';

/**
 * Synastry (docs/spec/11, Phase 7e): another person's natal chart laid over
 * the client's — a related person or another client — with the contacts
 * between the two, where each falls in the other's houses, and the composite
 * chart of the pair. Compared on the server from the two cached natal charts
 * on every request, never stored.
 *
 * Who is compared and which view is open live in the URL (`?with=person-5`,
 * `&view=composite`); the parent keeps them. Without a choice the first
 * linked person with a chart is compared.
 */
const props = defineProps({
    client: { type: Object, required: true },
    /** "person-5" or "client-7"; empty for the first linked person with a chart. */
    other: { type: String, default: '' },
    /** "synastry" or "composite". */
    view: { type: String, default: 'synastry' },
});

const emit = defineEmits(['update:other', 'update:view', 'open-tab']);

const { t } = useI18n();
const labels = useLabels();
const id = useId();

const VIEWS = ['synastry', 'composite'];
const CONTACTS_SHOWN = 20;

// The people linked to this client, as choices.
const options = ref(null);

onMounted(async () => {
    try {
        const { data } = await http.get(`/clients/${props.client.id}/relationships`);
        options.value = comparisonOptions(data.data);
    } catch {
        options.value = [];
    }
});

const chosen = computed(() => {
    const asked = parseWith(props.other);
    if (asked) return asked;

    const first = options.value?.find((option) => option.ready);

    return first ? { kind: first.kind, id: first.id } : null;
});
const chosenValue = computed(() => (chosen.value ? withValue(chosen.value.kind, chosen.value.id) : ''));
const nobody = computed(() => options.value !== null && !chosen.value);

const report = ref(null);
const state = ref('loading');
const busy = ref(false);

// A later request wins: a slow answer for an earlier choice must not replace it.
let latest = 0;

async function load() {
    const other = chosen.value;
    if (!other) return;

    const request = ++latest;
    busy.value = true;

    try {
        const { data } = await http.get(`/clients/${props.client.id}/synastry`, { params: synastryQuery(other) });
        if (request !== latest) return;

        report.value = data.data;
        state.value = data.data.status;
    } catch (error) {
        if (request !== latest) return;

        report.value = null;
        state.value = [404, 422].includes(error.response?.status) ? 'invalid' : 'failed';
    } finally {
        if (request === latest) busy.value = false;
    }
}

watch(chosenValue, load, { immediate: true });

// Choosing someone else: a linked person from the list, or any client through the search.
const picking = ref(false);
const pickError = ref(null);

function choose(value) {
    if (value === '__client') {
        picking.value = true;
        return;
    }

    picking.value = false;
    emit('update:other', value);
}

function pickClient(picked) {
    if (picked.id === props.client.id) {
        pickError.value = t('synastry.notSelf');
        return;
    }

    pickError.value = null;
    picking.value = false;
    emit('update:other', withValue('client', picked.id));
}

// A client found through the search is not among the links; the list still shows who is compared.
const extraOption = computed(() => {
    if (!chosen.value || options.value?.some((option) => option.value === chosenValue.value)) return null;

    return { value: chosenValue.value, name: report.value?.other?.full_name ?? '…' };
});

// "Partner", or "Sibling · Client" for a linked client.
const optionNote = (option) =>
    option.kind === 'client'
        ? `${labels.relationship(option.relationship)} · ${t('synastry.kinds.client')}`
        : labels.relationship(option.relationship);

const ready = computed(() => (state.value === 'ready' && report.value ? report.value : null));
const clientChart = computed(() => ready.value?.client.chart ?? null);
const otherChart = computed(() => ready.value?.other.chart ?? null);
const composite = computed(() => ready.value?.composite ?? null);

const clientName = computed(() => props.client.full_name);
const otherName = computed(() => report.value?.other?.full_name ?? '');
const firstName = (name) => name.split(' ')[0];

const lines = computed(() =>
    ready.value ? synastryLines(ready.value.contacts, otherChart.value, clientChart.value) : [],
);
const personal = computed(() => (ready.value ? personalContacts(ready.value.contacts) : []));

const showAll = ref(false);
watch(chosenValue, () => (showAll.value = false));
const shownContacts = computed(() => {
    const contacts = ready.value?.contacts ?? [];

    return showAll.value ? contacts : contacts.slice(0, CONTACTS_SHOWN);
});

const otherInClient = computed(() =>
    ready.value ? overlayChart(otherChart.value, clientChart.value, ready.value.overlays.other_in_client) : null,
);
const clientInOther = computed(() =>
    ready.value ? overlayChart(clientChart.value, otherChart.value, ready.value.overlays.client_in_other) : null,
);

const legend = computed(() => {
    const aspects = props.view === 'composite' ? (composite.value?.aspects ?? []) : (ready.value?.contacts ?? []);
    const present = new Set(aspects.map((aspect) => aspect.type));

    return ASPECT_ORDER.filter((type) => present.has(type));
});

const notices = computed(() => {
    if (!ready.value) return [];

    if (props.view === 'composite') {
        const accuracy = composite.value.time_accuracy;
        if (accuracy === 'unknown') return [['n-info', t('synastry.composite.noTimeNote')]];
        if (accuracy === 'approximate') return [['n-warn', t('synastry.composite.approximateNote')]];

        return [];
    }

    const list = [];
    for (const [name, chart] of [
        [otherName.value, otherChart.value],
        [clientName.value, clientChart.value],
    ]) {
        if (chart.time_accuracy === 'unknown') list.push(['n-info', t('synastry.noTimeNote', { name })]);
        if (chart.time_accuracy === 'approximate') list.push(['n-warn', t('synastry.approximateNote', { name })]);
    }

    return list;
});

const housesNote = computed(() => {
    const houses = composite.value?.houses;
    if (!houses) return null;

    return t(`synastry.composite.houses.${houses.method}`, {
        system: labels.houseSystem(houses.system),
        requested: labels.houseSystem(houses.requested_system),
    });
});

const zodiac = computed(() =>
    ready.value?.zodiac_mode === 'sidereal'
        ? t('chart.sidereal', { ayanamsa: labels.ayanamsa(ready.value.ayanamsa) })
        : t('chart.tropical'),
);

// Where incomplete birth data is completed: the client's form, or the other person's.
const completeTo = computed(() => {
    if (report.value?.side === 'client') return { name: 'clients.edit', params: { id: props.client.id } };

    const other = report.value?.other;
    if (!other) return null;

    return other.kind === 'person'
        ? { name: 'related-people.edit', params: { id: other.id } }
        : { name: 'clients.edit', params: { id: other.id } };
});
const incompleteName = computed(() => (report.value?.side === 'client' ? clientName.value : otherName.value));
</script>

<template>
    <div class="@container space-y-4">
        <!-- Nobody linked with a chart, and nobody chosen -->
        <section v-if="nobody && !picking" class="card">
            <div class="empty">
                <div class="e-glyph" aria-hidden="true">◌</div>
                <h3>{{ t('synastry.nobodyTitle') }}</h3>
                <p class="text-ink-3">{{ t('synastry.nobodyText') }}</p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <button type="button" class="btn btn-primary" @click="emit('open-tab', 'related')">
                        {{ t('synastry.addPerson') }}
                    </button>
                    <button type="button" class="btn" @click="picking = true">{{ t('synastry.pickClient') }}</button>
                </div>
            </div>
        </section>

        <section v-else class="card">
            <div class="card-head flex-wrap gap-y-2">
                <h2>
                    {{ otherName ? t('synastry.title', { client: clientName, other: otherName }) : t('synastry.tab') }}
                </h2>
                <span class="right flex flex-wrap items-center gap-2">
                    <span v-if="busy && report" class="text-xs text-ink-3" role="status">{{
                        t('synastry.loading')
                    }}</span>
                    <label :for="`${id}-with`" class="text-xs text-ink-3">{{ t('synastry.compareWith') }}</label>
                    <select
                        :id="`${id}-with`"
                        class="input h-8! w-auto! max-w-64 py-0! text-xs!"
                        :value="picking ? '__client' : chosenValue"
                        @change="choose($event.target.value)"
                    >
                        <option v-if="!chosenValue" value="" disabled>{{ t('synastry.choose') }}</option>
                        <option
                            v-for="option in options ?? []"
                            :key="option.value"
                            :value="option.value"
                            :disabled="!option.ready"
                        >
                            {{ option.name }} — {{ optionNote(option)
                            }}<template v-if="!option.ready"> ({{ t('synastry.noChart') }})</template>
                        </option>
                        <option v-if="extraOption" :value="extraOption.value">
                            {{ extraOption.name }} — {{ t('synastry.kinds.client') }}
                        </option>
                        <option value="__client">{{ t('synastry.anotherClient') }}</option>
                    </select>
                    <div class="seg" role="group" :aria-label="t('synastry.viewLabel')">
                        <button
                            v-for="option in VIEWS"
                            :key="option"
                            type="button"
                            :aria-pressed="view === option"
                            @click="emit('update:view', option)"
                        >
                            {{ t(`synastry.views.${option}`) }}
                        </button>
                    </div>
                </span>
            </div>

            <div class="card-body space-y-4">
                <div v-if="picking" class="max-w-md space-y-1.5">
                    <label :for="`${id}-client`" class="text-sm font-medium">{{ t('synastry.pickClient') }}</label>
                    <ClientPicker :input-id="`${id}-client`" @select="pickClient" />
                    <p v-if="pickError" class="error">{{ pickError }}</p>
                    <button type="button" class="btn btn-sm btn-ghost" @click="picking = false">
                        {{ t('synastry.cancel') }}
                    </button>
                </div>

                <p v-if="state === 'loading' && !nobody" class="text-ink-3" role="status">
                    {{ t('synastry.loading') }}
                </p>

                <div v-else-if="state === 'failed'" class="notice n-warn" role="alert">
                    {{ t('chart.unavailable') }}
                </div>

                <div v-else-if="state === 'invalid'" class="notice n-warn" role="alert">
                    {{ t('synastry.invalid') }}
                </div>

                <div v-else-if="state === 'incomplete'" class="notice n-warn" role="status">
                    <div>
                        <strong class="block">{{ t('synastry.incomplete', { name: incompleteName }) }}</strong>
                        {{ t('clients.missing.title') }}
                        {{ report.missing.map((key) => t(`clients.missing.${key}`)).join(', ') }}
                        <div v-if="completeTo" class="mt-1.5">
                            <RouterLink :to="completeTo" class="btn btn-sm">{{ t('synastry.complete') }}</RouterLink>
                        </div>
                    </div>
                </div>

                <template v-else-if="ready">
                    <div v-for="([tone, text], i) in notices" :key="i" class="notice" :class="tone" role="note">
                        {{ text }}
                    </div>

                    <div class="wheel-wrap" :class="{ 'opacity-60': busy }">
                        <ChartWheel
                            v-if="view === 'composite'"
                            :chart="composite"
                            :heading="t('synastry.composite.wheelTitle', { client: clientName, other: otherName })"
                        />
                        <ChartWheel
                            v-else
                            :chart="clientChart"
                            :name="clientName"
                            :outer="outerPoints(otherChart)"
                            :outer-label="otherName"
                            :contacts="lines"
                            :heading="t('synastry.wheelTitle', { client: clientName, other: otherName })"
                        />
                    </div>

                    <div
                        v-if="legend.length"
                        class="legend justify-center"
                        :aria-label="t('chart.aspects.legend')"
                        role="group"
                    >
                        <span v-for="type in legend" :key="type" :class="`asp-${type}`">
                            <svg viewBox="0 0 20 4" aria-hidden="true"><line x1="0" y1="2" x2="20" y2="2" /></svg>
                            {{ t(`aspectTypes.${type}`) }}
                        </span>
                    </div>
                    <p class="text-center text-xs text-ink-3">
                        {{
                            view === 'composite'
                                ? t('synastry.composite.wheelNote')
                                : t('synastry.wheelNote', { other: otherName, client: firstName(clientName) })
                        }}
                    </p>
                </template>
            </div>

            <div v-if="ready" class="engine-note">
                <span>{{ zodiac }}</span>
                <span v-if="clientChart.houses">{{ labels.houseSystem(clientChart.houses.system) }}</span>
                <span>{{
                    t('chart.engine', { engine: clientChart.engine.name, version: clientChart.engine.version })
                }}</span>
                <span>{{ t('synastry.engineNote') }}</span>
            </div>
        </section>

        <!-- Synastry: contacts, and each person in the other's houses -->
        <div
            v-if="ready && view !== 'composite'"
            class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <div class="space-y-4" :class="{ 'opacity-60': busy }">
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('synastry.personal.title') }}</h2>
                        <span class="right text-xs text-ink-3">{{ personal.length }}</span>
                    </div>
                    <p v-if="!personal.length" class="px-4 py-3 text-ink-3">{{ t('synastry.personal.none') }}</p>
                    <div v-else class="overflow-x-auto">
                        <SynastryContactsTable
                            :contacts="personal"
                            :other-name="firstName(otherName)"
                            :client-name="firstName(clientName)"
                            :caption="t('synastry.personal.title')"
                        />
                    </div>
                    <p class="engine-note font-sans!">
                        {{
                            t('synastry.personal.hint', { other: firstName(otherName), client: firstName(clientName) })
                        }}
                    </p>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('synastry.contacts.title') }}</h2>
                        <span class="right text-xs text-ink-3">{{
                            t('synastry.contacts.count', { count: ready.contacts.length }, ready.contacts.length)
                        }}</span>
                    </div>
                    <p v-if="!ready.contacts.length" class="px-4 py-3 text-ink-3">{{ t('synastry.contacts.none') }}</p>
                    <div v-else class="overflow-x-auto">
                        <SynastryContactsTable
                            :contacts="shownContacts"
                            :other-name="firstName(otherName)"
                            :client-name="firstName(clientName)"
                            :caption="t('synastry.contacts.title')"
                        />
                    </div>
                    <div v-if="ready.contacts.length > CONTACTS_SHOWN" class="px-4 py-2">
                        <button type="button" class="btn btn-sm btn-ghost" @click="showAll = !showAll">
                            {{
                                showAll
                                    ? t('synastry.contacts.showFewer')
                                    : t('synastry.contacts.showAll', { count: ready.contacts.length })
                            }}
                        </button>
                    </div>
                    <p class="engine-note font-sans!">{{ t('synastry.contacts.orbsHint') }}</p>
                </section>
            </div>

            <div class="space-y-4" :class="{ 'opacity-60': busy }">
                <section
                    v-for="[title, host, chart] in [
                        [otherName, clientName, otherInClient],
                        [clientName, otherName, clientInOther],
                    ]"
                    :key="title"
                    class="card"
                >
                    <div class="card-head">
                        <h2>{{ title }}</h2>
                        <span v-if="chart.houses" class="right text-xs text-ink-3">{{
                            t('synastry.overlay.inHouses', { name: firstName(host) })
                        }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <PositionsTable
                            :chart="chart"
                            :caption="t('synastry.overlay.caption', { name: title, host })"
                            :house-title="t('synastry.overlay.houseTitle', { name: host })"
                        />
                    </div>
                    <p v-if="!chart.houses" class="engine-note font-sans!">
                        {{ t('synastry.overlay.noHouses', { name: host }) }}
                    </p>
                </section>
            </div>
        </div>

        <!-- Composite: positions and cusps beside its aspects -->
        <div v-if="ready && view === 'composite'" class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <div class="space-y-4" :class="{ 'opacity-60': busy }">
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('synastry.composite.positions') }}</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <PositionsTable :chart="composite" :caption="t('synastry.composite.positions')" />
                    </div>
                    <p class="engine-note font-sans!">{{ t('synastry.composite.method') }}</p>
                </section>

                <section v-if="composite.houses" class="card">
                    <div class="card-head">
                        <h2>{{ t('chart.cusps.title') }}</h2>
                        <span class="right font-mono text-xs text-ink-3">{{
                            labels.houseSystem(composite.houses.system)
                        }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <HouseCuspsTable :cusps="composite.houses.cusps" />
                    </div>
                    <p class="engine-note font-sans!">{{ housesNote }}</p>
                </section>
            </div>

            <section class="card" :class="{ 'opacity-60': busy }">
                <div class="card-head">
                    <h2>{{ t('synastry.composite.aspects') }}</h2>
                    <span class="right text-xs text-ink-3">{{
                        t('chart.aspects.count', { count: composite.aspects.length }, composite.aspects.length)
                    }}</span>
                </div>
                <div class="overflow-x-auto">
                    <AspectsTable :aspects="composite.aspects" />
                </div>
                <p class="engine-note font-sans!">{{ t('synastry.contacts.orbsHint') }}</p>
            </section>
        </div>
    </div>
</template>
