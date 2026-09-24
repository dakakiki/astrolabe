<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import AspectsTable from '@/components/AspectsTable.vue';
import ChartWheel from '@/components/ChartWheel.vue';
import HouseCuspsTable from '@/components/HouseCuspsTable.vue';
import PositionsTable from '@/components/PositionsTable.vue';
import { useLabels } from '@/composables/useLabels';
import { ASPECT_ORDER } from '@/lib/chart';
import { formatCoordinate, formatRelative } from '@/lib/format';
import { splitLongitude } from '@/lib/zodiac';
import { useReferenceStore } from '@/stores/reference';

/**
 * A full natal chart (docs/spec/11): the wheel, positions with houses, house
 * cusps and aspects, with the notices an astrologer needs to read it right —
 * unknown, approximate or rectified time, a house system that could not be
 * drawn at the birth latitude, Whole Sign's first house. Every chart says
 * what produced it (docs/spec/02).
 *
 * `compact` stacks everything in one column for the consultation sidebar.
 * With `selectable`, the house system can be changed; the parent loads the
 * chart in the chosen system.
 */
const props = defineProps({
    chart: { type: Object, required: true },
    name: { type: String, default: '' },
    compact: { type: Boolean, default: false },
    selectable: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['house-system']);

const { t, locale } = useI18n();
const labels = useLabels();
const reference = useReferenceStore();
const id = useId();

onMounted(() => {
    if (props.selectable) reference.load();
});

const houses = computed(() => props.chart.houses);

// The system just picked stays in the select while its chart is loading.
const picked = ref(null);
const requestedSystem = computed(() => picked.value ?? houses.value?.requested_system ?? props.chart.house_system);

watch(
    () => props.busy,
    (busy) => {
        if (!busy) picked.value = null;
    },
);

function pickHouseSystem(value) {
    picked.value = value;
    emit('house-system', value);
}

const zodiac = computed(() =>
    props.chart.zodiac_mode === 'sidereal'
        ? t('chart.sidereal', { ayanamsa: labels.ayanamsa(props.chart.ayanamsa) })
        : t('chart.tropical'),
);

const ephemerisFiles = computed(() =>
    (props.chart.engine.ephemeris ?? '')
        .split(',')
        .map((file) => file.split(':')[0].replace('.se1', ''))
        .filter(Boolean)
        .join(', '),
);

const notices = computed(() => {
    const list = [];
    const chart = props.chart;

    if (chart.version < 2) list.push(['n-neutral', t('chart.oldSnapshot')]);
    if (chart.time_accuracy === 'unknown') list.push(['n-info', t('chart.noonNote')]);
    if (chart.time_accuracy === 'approximate') list.push(['n-warn', t('chart.approximateNote')]);
    if (chart.time_accuracy === 'rectified') list.push(['n-info', t('chart.rectifiedNote')]);

    if (houses.value && houses.value.system !== houses.value.requested_system) {
        list.push([
            'n-warn',
            t('chart.fallback', {
                requested: labels.houseSystem(houses.value.requested_system),
                used: labels.houseSystem(houses.value.system),
                latitude: formatCoordinate(chart.location?.latitude, 'lat'),
            }),
        ]);
    }

    if (houses.value?.system === 'whole_sign' && chart.angles) {
        list.push([
            'n-neutral',
            t('chart.wholeSignNote', { sign: t(`signs.${splitLongitude(chart.angles.asc).sign.key}`) }),
        ]);
    }

    return list;
});

const legend = computed(() => {
    const present = new Set((props.chart.aspects ?? []).map((aspect) => aspect.type));

    return ASPECT_ORDER.filter((type) => present.has(type));
});

const aspectCount = computed(() => props.chart.aspects?.length ?? 0);
</script>

<template>
    <div class="space-y-4">
        <div v-for="([tone, text], i) in notices" :key="i" class="notice" :class="tone" role="note">{{ text }}</div>

        <!-- Wheel -->
        <section :class="{ card: !compact }">
            <div v-if="!compact" class="card-head flex-wrap gap-y-2">
                <h2>{{ t('chart.title') }}</h2>
                <span class="right flex flex-wrap items-center gap-2">
                    <span v-if="busy" class="text-xs text-ink-3" role="status">{{ t('chart.switching') }}</span>
                    <template v-if="selectable && chart.angles">
                        <label :for="`${id}-hs`" class="text-xs text-ink-3">{{ t('chart.houseSystem') }}</label>
                        <select
                            :id="`${id}-hs`"
                            class="input h-8! w-auto! py-0! text-xs!"
                            :value="requestedSystem"
                            :disabled="busy"
                            @change="pickHouseSystem($event.target.value)"
                        >
                            <option
                                v-for="value in reference.data?.house_systems ?? [requestedSystem]"
                                :key="value"
                                :value="value"
                            >
                                {{ labels.houseSystem(value) }}
                            </option>
                        </select>
                    </template>
                    <span v-else-if="houses" class="font-mono text-xs text-ink-3">{{
                        labels.houseSystem(houses.system)
                    }}</span>
                </span>
            </div>

            <div :class="{ 'card-body': !compact }">
                <div class="wheel-wrap" :class="{ 'opacity-60': busy }">
                    <ChartWheel :chart="chart" :name="name" :compact="compact" />
                </div>

                <div
                    v-if="legend.length"
                    class="legend mt-4"
                    :class="{ 'justify-center': !compact }"
                    :aria-label="t('chart.aspects.legend')"
                    role="group"
                >
                    <span v-for="type in legend" :key="type" :class="`asp-${type}`">
                        <svg viewBox="0 0 20 4" aria-hidden="true"><line x1="0" y1="2" x2="20" y2="2" /></svg>
                        {{ t(`aspectTypes.${type}`) }}
                    </span>
                </div>
            </div>

            <div class="engine-note" :class="{ 'border-t-0! px-0!': compact }">
                <span>{{ zodiac }}</span>
                <span v-if="houses">{{ labels.houseSystem(houses.system) }}</span>
                <span
                    >{{ t('chart.engine', { engine: chart.engine.name, version: chart.engine.version })
                    }}<template v-if="ephemerisFiles"> ({{ ephemerisFiles }})</template></span
                >
                <span>{{ t('chart.tzdata', { version: chart.engine.tzdata }) }}</span>
                <span>{{ t('chart.julianDay', { value: chart.julian_day_ut.toFixed(5) }) }}</span>
                <span>{{ t('chart.calculated', { when: formatRelative(chart.calculated_at, locale) }) }}</span>
            </div>
        </section>

        <!-- Consultation sidebar: one column, details folded away -->
        <template v-if="compact">
            <div class="overflow-x-auto">
                <PositionsTable :chart="chart" compact />
            </div>
            <details v-if="chart.aspects">
                <summary class="cursor-pointer text-sm font-medium">
                    {{ t('chart.aspects.show', { count: aspectCount }) }}
                </summary>
                <div class="mt-2 overflow-x-auto">
                    <AspectsTable :aspects="chart.aspects" />
                </div>
            </details>
            <details v-if="houses">
                <summary class="cursor-pointer text-sm font-medium">
                    {{ t('chart.cusps.title') }} · {{ labels.houseSystem(houses.system) }}
                </summary>
                <div class="mt-2 overflow-x-auto">
                    <HouseCuspsTable :cusps="houses.cusps" />
                </div>
            </details>
        </template>

        <!-- Client profile: positions and cusps beside the aspects -->
        <div v-else class="grid items-start gap-4 lg:grid-cols-2">
            <div class="space-y-4">
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('chart.positionsTitle') }}</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <PositionsTable :chart="chart" />
                    </div>
                </section>

                <section v-if="houses" class="card">
                    <div class="card-head">
                        <h2>{{ t('chart.cusps.title') }}</h2>
                        <span class="right font-mono text-xs text-ink-3">{{ labels.houseSystem(houses.system) }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <HouseCuspsTable :cusps="houses.cusps" />
                    </div>
                </section>
            </div>

            <section v-if="chart.aspects" class="card">
                <div class="card-head">
                    <h2>{{ t('chart.aspects.title') }}</h2>
                    <span class="right text-xs text-ink-3">{{
                        t('chart.aspects.count', { count: aspectCount }, aspectCount)
                    }}</span>
                </div>
                <div class="overflow-x-auto">
                    <AspectsTable :aspects="chart.aspects" />
                </div>
                <p class="engine-note font-sans!">{{ t('chart.aspects.orbsHint') }}</p>
            </section>
        </div>
    </div>
</template>
