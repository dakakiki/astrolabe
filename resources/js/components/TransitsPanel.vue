<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import ChartWheel from '@/components/ChartWheel.vue';
import PositionsTable from '@/components/PositionsTable.vue';
import { useLabels } from '@/composables/useLabels';
import { ASPECT_GLYPHS, ASPECT_ORDER, formatOrb } from '@/lib/chart';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { contactLines, exactDates, momentInput, transitQuery } from '@/lib/transits';
import { BODY_GLYPHS } from '@/lib/zodiac';
import { useAuthStore } from '@/stores/auth';

/**
 * Transits to a natal chart (docs/spec/11, Phase 7a): the planets at a chosen
 * moment on an outer ring around the natal wheel, the contacts they make to
 * natal points within the transit orbs — with the dates the slow ones are
 * exact — and where each planet falls in the natal houses. Calculated on the
 * server for every request, never stored.
 *
 * The moment is wall-clock time on the viewer's own clock ("" = now). The
 * parent keeps it (in the URL), so a link can open transits for a date.
 */
const props = defineProps({
    /** The transits endpoint: /clients/{id}/transits or /related-people/{id}/transits. */
    endpoint: { type: String, required: true },
    name: { type: String, default: '' },
    /** Local wall-clock time ("2026-10-05T15:00"); empty for now. */
    at: { type: String, default: '' },
    /** Where the birth data is completed when a chart cannot be drawn. */
    editTo: { type: [Object, String], required: true },
});

const emit = defineEmits(['update:at']);

const { t, locale } = useI18n();
const labels = useLabels();
const auth = useAuthStore();
const id = useId();

const zone = computed(() => auth.user?.timezone ?? 'UTC');

const report = ref(null);
const state = ref('loading');
const missing = ref([]);
const momentError = ref(null);
const busy = ref(false);

// A later request wins: a slow answer for an earlier moment must not replace it.
let latest = 0;

async function load() {
    const request = ++latest;
    busy.value = true;
    momentError.value = null;

    try {
        const { data } = await http.get(props.endpoint, { params: transitQuery(props.at, zone.value) });
        if (request !== latest) return;

        if (data.data.status === 'ready') {
            report.value = data.data;
            state.value = 'ready';
        } else {
            report.value = null;
            missing.value = data.data.missing ?? [];
            state.value = 'incomplete';
        }
    } catch (error) {
        if (request !== latest) return;

        if (error.response?.status === 422) {
            momentError.value = error.response.data.errors?.at?.[0] ?? error.response.data.message;
            if (!report.value) state.value = 'invalid';
        } else {
            report.value = null;
            state.value = 'failed';
        }
    } finally {
        if (request === latest) busy.value = false;
    }
}

watch(() => [props.endpoint, props.at], load, { immediate: true });

const shownMoment = computed(() => momentInput(props.at, report.value?.moment, zone.value));

function pick(value) {
    emit('update:at', value);
}

// Already on "now": calculate it again, the sky has moved on.
function now() {
    if (props.at) emit('update:at', '');
    else load();
}

const natal = computed(() => report.value?.natal ?? null);
const lines = computed(() =>
    report.value ? contactLines(report.value.contacts, report.value.positions, report.value.natal) : [],
);
const retrograde = computed(() =>
    Object.fromEntries((report.value?.positions ?? []).map((position) => [position.body, position.retrograde])),
);
const positionsChart = computed(() => ({
    positions: report.value.positions,
    houses: natal.value.houses ?? null,
    angles: null,
    moon_range: null,
}));

const legend = computed(() => {
    const present = new Set((report.value?.contacts ?? []).map((contact) => contact.type));

    return ASPECT_ORDER.filter((type) => present.has(type));
});

const notices = computed(() => {
    const accuracy = natal.value?.time_accuracy;
    if (accuracy === 'unknown') return [['n-info', t('transits.noTimeNote')]];
    if (accuracy === 'approximate') return [['n-warn', t('transits.approximateNote')]];

    return [];
});

const zodiac = computed(() =>
    report.value?.zodiac_mode === 'sidereal'
        ? t('chart.sidereal', { ayanamsa: labels.ayanamsa(report.value.ayanamsa) })
        : t('chart.tropical'),
);

const glyph = (key) => BODY_GLYPHS[key] ?? t(`chart.angleAbbr.${key}`);
const isAngle = (key) => !BODY_GLYPHS[key];
const dateOf = (iso) => formatDateTime(iso, locale.value, report.value.timezone, { dateStyle: 'medium' });
</script>

<template>
    <div class="@container space-y-4">
        <section class="card">
            <div class="card-head flex-wrap gap-y-2">
                <h2>{{ t('transits.title') }}</h2>
                <span class="right flex flex-wrap items-center gap-2">
                    <span v-if="busy && report" class="text-xs text-ink-3" role="status">{{
                        t('transits.loading')
                    }}</span>
                    <label :for="`${id}-at`" class="text-xs text-ink-3">{{ t('transits.at') }}</label>
                    <input
                        :id="`${id}-at`"
                        type="datetime-local"
                        class="input h-8! w-auto! py-0! text-xs!"
                        :value="shownMoment"
                        min="1801-01-01T00:00"
                        max="2398-12-31T23:59"
                        :aria-invalid="momentError ? 'true' : undefined"
                        :aria-describedby="`${id}-zone`"
                        @change="pick($event.target.value)"
                    />
                    <button type="button" class="btn btn-sm" :disabled="busy" @click="now">
                        {{ t('transits.now') }}
                    </button>
                </span>
            </div>

            <div class="card-body space-y-4">
                <p :id="`${id}-zone`" class="text-right text-xs text-ink-3">
                    <span v-if="momentError" class="error mr-2">{{ momentError }}</span>
                    {{ t('transits.inZone', { zone }) }}
                </p>

                <p v-if="state === 'loading'" class="text-ink-3" role="status">{{ t('transits.loading') }}</p>

                <div v-else-if="state === 'failed'" class="notice n-warn" role="alert">
                    {{ t('chart.unavailable') }}
                </div>

                <div v-else-if="state === 'incomplete'" class="notice n-warn" role="status">
                    <div>
                        <strong class="block">{{ t('transits.incomplete') }}</strong>
                        {{ t('clients.missing.title') }}
                        {{ missing.map((key) => t(`clients.missing.${key}`)).join(', ') }}
                        <div class="mt-1.5">
                            <RouterLink :to="editTo" class="btn btn-sm">{{ t('transits.complete') }}</RouterLink>
                        </div>
                    </div>
                </div>

                <template v-else-if="report">
                    <div v-for="([tone, text], i) in notices" :key="i" class="notice" :class="tone" role="note">
                        {{ text }}
                    </div>

                    <div class="wheel-wrap" :class="{ 'opacity-60': busy }">
                        <ChartWheel :chart="natal" :name="name" :transits="report.positions" :contacts="lines" />
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
                    <p class="text-center text-xs text-ink-3">{{ t('transits.wheelNote') }}</p>
                </template>
            </div>

            <div v-if="report" class="engine-note">
                <span>{{ zodiac }}</span>
                <span v-if="natal.houses">{{ labels.houseSystem(natal.houses.system) }}</span>
                <span>{{ t('chart.engine', { engine: report.engine.name, version: report.engine.version }) }}</span>
                <span>{{ t('chart.julianDay', { value: report.julian_day_ut.toFixed(5) }) }}</span>
                <span>{{ t('transits.engineNote') }}</span>
            </div>
        </section>

        <div v-if="report" class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <!-- Contacts to the natal chart -->
            <section class="card" :class="{ 'opacity-60': busy }">
                <div class="card-head">
                    <h2>{{ t('transits.contacts.title') }}</h2>
                    <span class="right text-xs text-ink-3">{{
                        t('transits.contacts.count', { count: report.contacts.length }, report.contacts.length)
                    }}</span>
                </div>

                <p v-if="!report.contacts.length" class="px-4 py-3 text-ink-3">{{ t('transits.contacts.none') }}</p>

                <div v-else class="overflow-x-auto">
                    <table class="data">
                        <caption class="sr-only">
                            {{
                                t('transits.contacts.title')
                            }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ t('transits.contacts.transit') }}</th>
                                <th scope="col">{{ t('chart.aspects.aspect') }}</th>
                                <th scope="col">{{ t('transits.contacts.natal') }}</th>
                                <th scope="col" class="text-right">{{ t('transits.contacts.orb') }}</th>
                                <th scope="col">
                                    <span class="sr-only">{{ t('chart.aspects.motion') }}</span>
                                </th>
                                <th scope="col">{{ t('transits.contacts.exact') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="contact in report.contacts"
                                :key="`${contact.transit}-${contact.natal}-${contact.type}`"
                                class="cursor-default!"
                            >
                                <th scope="row" class="font-normal whitespace-nowrap">
                                    <span
                                        class="mr-1.5 inline-block w-4 text-center text-base text-link"
                                        aria-hidden="true"
                                        >{{ glyph(contact.transit) }}</span
                                    >{{ labels.point(contact.transit) }}
                                    <abbr
                                        v-if="retrograde[contact.transit]"
                                        class="ml-0.5 font-mono text-warn no-underline"
                                        :title="t('chart.retrograde')"
                                        :aria-label="t('chart.retrograde')"
                                        >℞</abbr
                                    >
                                </th>
                                <td class="whitespace-nowrap" :class="`asp-${contact.type}`">
                                    <span class="aspect-glyph mr-1.5 text-base" aria-hidden="true">{{
                                        ASPECT_GLYPHS[contact.type]
                                    }}</span>
                                    {{ t(`aspectTypes.${contact.type}`) }}
                                </td>
                                <td class="whitespace-nowrap">
                                    <span
                                        class="mr-1.5 inline-block w-4 text-center text-ink-2"
                                        :class="isAngle(contact.natal) ? 'font-mono text-[10px]' : 'text-base'"
                                        aria-hidden="true"
                                        >{{ glyph(contact.natal) }}</span
                                    >{{ labels.point(contact.natal) }}
                                </td>
                                <td class="text-right font-mono whitespace-nowrap">{{ formatOrb(contact.orb) }}</td>
                                <td class="text-xs text-ink-3">
                                    <template v-if="contact.applying !== null">
                                        {{
                                            contact.applying
                                                ? t('chart.aspects.applying')
                                                : t('chart.aspects.separating')
                                        }}
                                    </template>
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    <template v-if="contact.exact">
                                        <span
                                            v-for="day in exactDates(contact.exact, report.moment)"
                                            :key="day.date"
                                            class="block"
                                            :class="
                                                day.nearest
                                                    ? 'font-medium text-ink'
                                                    : day.past
                                                      ? 'text-ink-4'
                                                      : 'text-ink-3'
                                            "
                                            >{{ dateOf(day.date) }}</span
                                        >
                                        <span v-if="!contact.exact.length" class="text-ink-4">{{
                                            t('transits.contacts.exactNone')
                                        }}</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="engine-note font-sans!">
                    {{ t('transits.contacts.exactHint') }} {{ t('transits.orbsHint') }}
                </p>
            </section>

            <!-- The planets at this moment, in the natal houses -->
            <section class="card" :class="{ 'opacity-60': busy }">
                <div class="card-head">
                    <h2>{{ t('transits.positions.title') }}</h2>
                    <span class="right font-mono text-xs text-ink-3">{{
                        formatDateTime(report.moment, locale, report.timezone)
                    }}</span>
                </div>
                <div class="overflow-x-auto">
                    <PositionsTable
                        :chart="positionsChart"
                        :caption="t('transits.positions.title')"
                        :house-title="t('transits.positions.house')"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
