<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import PositionsTable from '@/components/PositionsTable.vue';
import { useLabels } from '@/composables/useLabels';
import { addDays, todayIn } from '@/lib/calendar';
import { ASPECT_GLYPHS } from '@/lib/chart';
import { formatDateTime } from '@/lib/datetime';
import { formatDate } from '@/lib/format';
import http from '@/lib/http';
import {
    aspectTone,
    EVENT_TYPES,
    filterArcs,
    filterEvents,
    groupByDay,
    SKY_ASPECTS,
    SKY_BODIES,
    SKY_PERIODS,
    skyQuery,
} from '@/lib/sky';
import { BODY_GLYPHS, formatDegrees, SIGNS, splitLongitude } from '@/lib/zodiac';
import { useAuthStore } from '@/stores/auth';

/**
 * The sky itself, nobody's chart (docs/spec/02, "Nebo"; Phase 7d): the minute
 * each aspect between two planets is exact, stations, ingresses, new and full
 * moons, grouped by day on the astrologer's clock; aspects a retrograde loop
 * brings back two or three times; and where the planets stand. The period and
 * the filters live in the address, so a view can be bookmarked.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const labels = useLabels();
const auth = useAuthStore();

const zone = computed(() => auth.user?.timezone ?? 'UTC');
const state = computed(() => skyQuery(route.query));
const from = computed(() => state.value.from ?? todayIn(zone.value));
const filters = computed(() => ({ body: state.value.body, type: state.value.type, aspect: state.value.aspect }));

const calendar = ref(null);
const loading = ref(false);
const failed = ref(false);
let latest = 0;

async function load() {
    const request = ++latest;
    loading.value = true;
    failed.value = false;

    try {
        const { data } = await http.get('/sky', { params: { from: from.value, days: state.value.days } });
        if (request === latest) calendar.value = data.data;
    } catch {
        if (request === latest) {
            calendar.value = null;
            failed.value = true;
        }
    } finally {
        if (request === latest) loading.value = false;
    }
}

watch(() => [from.value, state.value.days], load, { immediate: true });

/** Change the address; empty values and the defaults are left out of it. */
function go(changes) {
    const query = { ...route.query, ...changes };
    for (const key of Object.keys(query)) {
        if (query[key] === '' || query[key] === null || query[key] === undefined) delete query[key];
    }
    if (Number(query.days) === 30) delete query.days;
    router.replace({ query });
}

function shift(direction) {
    go({ from: addDays(from.value, direction * state.value.days) });
}

const events = computed(() => filterEvents(calendar.value?.events ?? [], filters.value));
const days = computed(() => groupByDay(events.value, zone.value));
const arcs = computed(() => filterArcs(calendar.value?.arcs ?? [], filters.value));
const positions = computed(() => (calendar.value ? { positions: calendar.value.positions } : null));

const time = (at) => formatDateTime(at, locale.value, zone.value, { timeStyle: 'short' });
const date = (at) => formatDateTime(at, locale.value, zone.value, { dateStyle: 'medium' });

const elementColor = { fire: 'text-fire', earth: 'text-earth', air: 'text-air', water: 'text-water' };

/** "12°34′" and the sign's glyph, coloured by element, with the sign's name for screen readers. */
function place(longitude) {
    const { sign } = splitLongitude(longitude);

    return {
        degrees: formatDegrees(longitude),
        glyph: sign.glyph,
        name: t(`signs.${sign.key}`),
        color: elementColor[sign.element],
    };
}

function signOf(key) {
    const sign = SIGNS.find((item) => item.key === key);

    return { glyph: sign.glyph, name: t(`signs.${key}`), color: elementColor[sign.element] };
}

const body = (key) => ({ glyph: BODY_GLYPHS[key], name: t(`bodies.${key}`) });
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('sky.eyebrow') }}</div>
        <h1>{{ t('sky.title') }}</h1>
        <div class="sub">{{ t('sky.sub') }}</div>
    </div>

    <div class="card mb-4 flex flex-wrap items-end gap-2 p-3">
        <label class="grid gap-1 text-xs text-ink-3">
            {{ t('sky.from') }}
            <input
                :value="from"
                type="date"
                class="input h-9! w-auto!"
                @change="go({ from: $event.target.value || null })"
            />
        </label>
        <div class="flex gap-1">
            <button type="button" class="btn btn-sm h-9" :aria-label="t('sky.previous')" @click="shift(-1)">‹</button>
            <button type="button" class="btn btn-sm h-9" @click="go({ from: null })">{{ t('sky.today') }}</button>
            <button type="button" class="btn btn-sm h-9" :aria-label="t('sky.next')" @click="shift(1)">›</button>
        </div>
        <label class="grid gap-1 text-xs text-ink-3">
            {{ t('sky.period') }}
            <select :value="state.days" class="input h-9! w-auto!" @change="go({ days: $event.target.value })">
                <option v-for="period in SKY_PERIODS" :key="period" :value="period">
                    {{ t(`sky.periods.${period}`) }}
                </option>
            </select>
        </label>
        <label class="grid gap-1 text-xs text-ink-3">
            {{ t('sky.body') }}
            <select :value="state.body" class="input h-9! w-auto!" @change="go({ body: $event.target.value })">
                <option value="">{{ t('sky.allBodies') }}</option>
                <option v-for="key in SKY_BODIES" :key="key" :value="key">{{ t(`bodies.${key}`) }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-xs text-ink-3">
            {{ t('sky.kind') }}
            <select :value="state.type" class="input h-9! w-auto!" @change="go({ type: $event.target.value })">
                <option value="">{{ t('sky.allKinds') }}</option>
                <option v-for="type in EVENT_TYPES" :key="type" :value="type">{{ t(`sky.kinds.${type}`) }}</option>
            </select>
        </label>
        <label v-if="!state.type || state.type === 'aspect'" class="grid gap-1 text-xs text-ink-3">
            {{ t('sky.aspect') }}
            <select :value="state.aspect" class="input h-9! w-auto!" @change="go({ aspect: $event.target.value })">
                <option value="">{{ t('sky.allAspects') }}</option>
                <option v-for="aspect in SKY_ASPECTS" :key="aspect" :value="aspect">
                    {{ ASPECT_GLYPHS[aspect] }} {{ t(`aspectTypes.${aspect}`) }}
                </option>
            </select>
        </label>
        <span v-if="calendar" class="ml-auto self-center text-xs text-ink-3" role="status">
            {{ t('sky.count', { count: events.length }, events.length) }}
        </span>
    </div>

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="card min-w-0" :aria-busy="loading ? 'true' : 'false'">
            <div class="card-head">
                <h2>{{ t('sky.eventsTitle') }}</h2>
                <span class="right text-xs text-ink-3">{{ t('sky.timesIn', { zone }) }}</span>
            </div>

            <p v-if="loading && !calendar" class="card-body text-ink-3" role="status">{{ t('sky.loading') }}</p>
            <div v-else-if="failed" class="card-body">
                <div class="notice n-warn" role="alert">{{ t('chart.unavailable') }}</div>
            </div>
            <template v-else-if="calendar">
                <p v-if="calendar.zodiac_mode === 'sidereal'" class="notice n-neutral m-3">
                    {{ t('sky.sidereal', { ayanamsa: labels.ayanamsa(calendar.ayanamsa) }) }}
                </p>

                <div v-for="group in days" :key="group.day">
                    <h3
                        class="border-y border-line-soft bg-surface-2 px-4 py-2 font-mono text-[11.5px] font-normal tracking-wider text-ink-3 uppercase"
                    >
                        {{ formatDate(group.day, locale, 'full') }}
                    </h3>
                    <ul>
                        <li
                            v-for="(event, index) in group.events"
                            :key="`${event.at}-${index}`"
                            class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-line-soft px-4 py-2.5 last:border-b-0"
                        >
                            <span class="w-16 shrink-0 font-mono text-xs text-ink-3">{{ time(event.at) }}</span>

                            <span class="min-w-0 flex-1">
                                <template v-if="event.type === 'aspect'">
                                    <span class="whitespace-nowrap">
                                        <span aria-hidden="true">{{ body(event.bodies[0].body).glyph }}</span>
                                        {{ body(event.bodies[0].body).name }}
                                        <abbr
                                            v-if="event.bodies[0].retrograde"
                                            class="text-danger no-underline"
                                            :title="t('chart.retrograde')"
                                            >℞</abbr
                                        >
                                    </span>
                                    <span class="px-1 text-ink-3" aria-hidden="true">{{
                                        ASPECT_GLYPHS[event.aspect]
                                    }}</span>
                                    <span class="whitespace-nowrap">
                                        <span aria-hidden="true">{{ body(event.bodies[1].body).glyph }}</span>
                                        {{ body(event.bodies[1].body).name }}
                                        <abbr
                                            v-if="event.bodies[1].retrograde"
                                            class="text-danger no-underline"
                                            :title="t('chart.retrograde')"
                                            >℞</abbr
                                        >
                                    </span>
                                    <span class="block font-mono text-[11px] text-ink-3">
                                        <template v-for="(item, side) in event.bodies" :key="item.body">
                                            <template v-if="side"> · </template>
                                            {{ place(item.longitude).degrees }}
                                            <span
                                                :class="place(item.longitude).color"
                                                :title="place(item.longitude).name"
                                                >{{ place(item.longitude).glyph }}</span
                                            >
                                        </template>
                                    </span>
                                </template>

                                <template v-else-if="event.type === 'station'">
                                    <span aria-hidden="true">{{ body(event.body).glyph }}</span>
                                    {{ body(event.body).name }} {{ t(`sky.stations.${event.direction}`) }}
                                    <span class="block font-mono text-[11px] text-ink-3">
                                        {{ place(event.longitude).degrees }}
                                        <span
                                            :class="place(event.longitude).color"
                                            :title="place(event.longitude).name"
                                            >{{ place(event.longitude).glyph }}</span
                                        >
                                    </span>
                                </template>

                                <template v-else-if="event.type === 'ingress'">
                                    <span aria-hidden="true">{{ body(event.body).glyph }}</span>
                                    {{ body(event.body).name }}
                                    <abbr
                                        v-if="event.retrograde"
                                        class="text-danger no-underline"
                                        :title="t('chart.retrograde')"
                                        >℞</abbr
                                    >
                                    {{ event.retrograde ? t('sky.backInto') : t('sky.enters') }}
                                    <span :class="signOf(event.sign).color" aria-hidden="true">{{
                                        signOf(event.sign).glyph
                                    }}</span>
                                    {{ signOf(event.sign).name }}
                                </template>

                                <template v-else>
                                    <span aria-hidden="true">{{ event.phase === 'new' ? '●' : '○' }}</span>
                                    {{ t(`sky.lunations.${event.phase}`) }}
                                    <span class="block font-mono text-[11px] text-ink-3">
                                        {{ place(event.longitude).degrees }}
                                        <span
                                            :class="place(event.longitude).color"
                                            :title="place(event.longitude).name"
                                            >{{ place(event.longitude).glyph }}</span
                                        >
                                    </span>
                                </template>
                            </span>

                            <span
                                class="badge"
                                :class="event.type === 'aspect' ? `b-${aspectTone(event.aspect)}` : 'b-plain'"
                            >
                                {{
                                    event.type === 'aspect'
                                        ? t(`aspectTypes.${event.aspect}`)
                                        : t(`sky.badges.${event.type}`)
                                }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div v-if="!days.length" class="empty">
                    <div class="e-glyph" aria-hidden="true">◌</div>
                    <h3>{{ t('sky.emptyTitle') }}</h3>
                    <p>{{ t('sky.emptyText') }}</p>
                </div>

                <div class="engine-note">
                    <span>{{ calendar.engine.name }} {{ calendar.engine.version }}</span>
                    <span>{{ t('sky.exactToMinute') }}</span>
                    <span>{{ t('sky.onRequest') }}</span>
                </div>
            </template>
        </section>

        <div class="grid min-w-0 grid-cols-1 gap-4">
            <!-- Arcs are aspects: nothing to show while only stations, ingresses or lunations are listed. -->
            <section v-if="!state.type || state.type === 'aspect'" class="card">
                <div class="card-head">
                    <h2>{{ t('sky.arcsTitle') }}</h2>
                </div>
                <div class="card-body">
                    <ul v-if="arcs.length" class="space-y-3">
                        <li v-for="arc in arcs" :key="`${arc.bodies.join('-')}-${arc.aspect}-${arc.passes[0].at}`">
                            <div class="font-mono text-[12.5px]">
                                <span aria-hidden="true">{{ body(arc.bodies[0]).glyph }}</span>
                                {{ body(arc.bodies[0]).name }}
                                <span class="text-ink-3" :title="t(`aspectTypes.${arc.aspect}`)">{{
                                    ASPECT_GLYPHS[arc.aspect]
                                }}</span>
                                <span aria-hidden="true">{{ body(arc.bodies[1]).glyph }}</span>
                                {{ body(arc.bodies[1]).name }}
                                <span class="sr-only">{{ t(`aspectTypes.${arc.aspect}`) }}</span>
                            </div>
                            <div class="text-xs text-ink-3">
                                {{ t('sky.passes', { count: arc.passes.length }, arc.passes.length) }} ·
                                <template v-for="(pass, index) in arc.passes" :key="pass.at">
                                    <template v-if="index"> → </template>
                                    <span
                                        :class="pass.in_period ? 'text-ink-2' : 'text-ink-4'"
                                        :title="pass.in_period ? null : t('sky.outside')"
                                        >{{ date(pass.at) }}</span
                                    >
                                </template>
                            </div>
                        </li>
                    </ul>
                    <p v-else-if="calendar" class="text-sm text-ink-3">{{ t('sky.arcsNone') }}</p>
                    <p class="mt-3 border-t border-line-soft pt-2.5 text-xs text-ink-3">{{ t('sky.arcsIntro') }}</p>
                </div>
            </section>

            <section v-if="positions" class="card">
                <div class="card-head">
                    <h2>{{ t('sky.positionsTitle') }}</h2>
                    <span class="right text-xs text-ink-3">{{
                        t('sky.positionsAt', { time: formatDateTime(calendar.positions_at, locale, zone) })
                    }}</span>
                </div>
                <div class="overflow-x-auto">
                    <PositionsTable :chart="positions" compact :caption="t('sky.positionsTitle')" />
                </div>
            </section>
        </div>
    </div>
</template>
