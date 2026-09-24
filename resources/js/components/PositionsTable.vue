<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { useLabels } from '@/composables/useLabels';
import { formatRelative } from '@/lib/format';
import { BODY_GLYPHS, formatDegrees, splitLongitude } from '@/lib/zodiac';

/**
 * The natal positions as a table — the readable alternative to the chart wheel
 * for phones and screen readers (docs/spec/11) — with the engine that
 * produced them underneath, as every chart must show (docs/spec/02).
 */
const props = defineProps({ chart: { type: Object, required: true } });

const { t, locale } = useI18n();
const labels = useLabels();

const rows = computed(() =>
    props.chart.positions.map((position) => ({
        ...position,
        isMoonRange: position.body === 'moon' && props.chart.moon_range,
    })),
);

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

const caption = computed(() => `${t('chart.title')}, ${zodiac.value}`);

const elementColor = { fire: 'text-fire', earth: 'text-earth', air: 'text-air', water: 'text-water' };

function signCell(longitude) {
    const { sign } = splitLongitude(longitude);
    return { glyph: sign.glyph, name: t(`signs.${sign.key}`), color: elementColor[sign.element] };
}
</script>

<template>
    <div>
        <div v-if="chart.moon_range" class="notice n-info mb-3">
            {{ t('chart.noonNote') }}
        </div>

        <table class="data">
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('chart.body') }}</th>
                    <th scope="col">{{ t('chart.sign') }}</th>
                    <th scope="col">{{ t('chart.position') }}</th>
                    <th scope="col">
                        <span class="sr-only">{{ t('chart.motion') }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.body" class="cursor-default!">
                    <th scope="row" class="font-normal">
                        <span class="mr-2 inline-block w-4 text-center text-base text-ink-2" aria-hidden="true">{{
                            BODY_GLYPHS[row.body]
                        }}</span>
                        <span :class="row.body === 'mean_node' ? 'text-ink-3' : 'text-ink'">{{
                            t(`bodies.${row.body}`)
                        }}</span>
                    </th>

                    <!-- Unknown time: the Moon is a span, possibly across a sign boundary. -->
                    <td v-if="row.isMoonRange" colspan="2">
                        <span class="font-mono">{{ formatDegrees(chart.moon_range.from) }}</span>
                        <span :class="signCell(chart.moon_range.from).color" class="mx-1" aria-hidden="true">{{
                            signCell(chart.moon_range.from).glyph
                        }}</span>
                        <span class="text-ink-3">{{ signCell(chart.moon_range.from).name }}</span>
                        <span class="mx-1.5 text-ink-4">–</span>
                        <span class="font-mono">{{ formatDegrees(chart.moon_range.to) }}</span>
                        <span :class="signCell(chart.moon_range.to).color" class="mx-1" aria-hidden="true">{{
                            signCell(chart.moon_range.to).glyph
                        }}</span>
                        <span class="text-ink-3">{{ signCell(chart.moon_range.to).name }}</span>
                        <div class="text-xs text-ink-3">{{ t('chart.moonRange') }}</div>
                    </td>

                    <template v-else>
                        <td>
                            <span :class="signCell(row.longitude).color" class="mr-1.5 text-base" aria-hidden="true">{{
                                signCell(row.longitude).glyph
                            }}</span>
                            {{ signCell(row.longitude).name }}
                        </td>
                        <td class="font-mono">{{ formatDegrees(row.longitude) }}</td>
                    </template>

                    <td class="text-right">
                        <abbr
                            v-if="row.retrograde"
                            class="font-mono text-warn no-underline"
                            :title="t('chart.retrograde')"
                            :aria-label="t('chart.retrograde')"
                            >℞</abbr
                        >
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="mt-3 text-xs text-ink-4">
            {{ zodiac }} · {{ t('chart.engine', { engine: chart.engine.name, version: chart.engine.version }) }}
            <template v-if="ephemerisFiles"> ({{ ephemerisFiles }})</template>
            · {{ t('chart.tzdata', { version: chart.engine.tzdata }) }} ·
            {{ t('chart.calculated', { when: formatRelative(chart.calculated_at, locale) }) }}
        </p>
        <p class="mt-1 text-xs text-ink-4">{{ t('chart.housesSoon') }}</p>
    </div>
</template>
