<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { houseOf } from '@/lib/chart';
import { BODY_GLYPHS, formatDegrees, splitLongitude } from '@/lib/zodiac';

/**
 * The natal positions as a table — the readable alternative to the chart wheel
 * for phones and screen readers (docs/spec/11): each body with its sign,
 * degree, house and motion, then the Ascendant and Midheaven.
 */
const props = defineProps({
    chart: { type: Object, required: true },
    // Narrow columns: sign names are left to screen readers, the glyph shows the sign.
    compact: { type: Boolean, default: false },
});

const { t } = useI18n();

const hasHouses = computed(() => Boolean(props.chart.houses));

const rows = computed(() =>
    props.chart.positions.map((position) => ({
        ...position,
        isMoonRange: position.body === 'moon' && props.chart.moon_range,
    })),
);

const angleRows = computed(() =>
    props.chart.angles
        ? ['asc', 'mc'].map((key) => ({
              key,
              longitude: props.chart.angles[key],
              house: houseOf(props.chart.angles[key], props.chart.houses.cusps),
          }))
        : [],
);

const elementColor = { fire: 'text-fire', earth: 'text-earth', air: 'text-air', water: 'text-water' };

function signCell(longitude) {
    const { sign } = splitLongitude(longitude);
    return { glyph: sign.glyph, name: t(`signs.${sign.key}`), color: elementColor[sign.element] };
}
</script>

<template>
    <table class="data" :class="{ dense: compact }">
        <caption class="sr-only">
            {{
                t('chart.positionsTitle')
            }}
        </caption>
        <thead>
            <tr>
                <th scope="col">{{ t('chart.body') }}</th>
                <th scope="col">{{ t('chart.sign') }}</th>
                <th scope="col">{{ t('chart.position') }}</th>
                <th v-if="hasHouses" scope="col" class="text-right">
                    <abbr :title="t('chart.house')" class="no-underline">{{ t('chart.houseShort') }}</abbr>
                </th>
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
                    <span class="text-ink-3" :class="{ 'sr-only': compact }">{{
                        signCell(chart.moon_range.from).name
                    }}</span>
                    <span class="mx-1.5 text-ink-4">–</span>
                    <span class="font-mono">{{ formatDegrees(chart.moon_range.to) }}</span>
                    <span :class="signCell(chart.moon_range.to).color" class="mx-1" aria-hidden="true">{{
                        signCell(chart.moon_range.to).glyph
                    }}</span>
                    <span class="text-ink-3" :class="{ 'sr-only': compact }">{{
                        signCell(chart.moon_range.to).name
                    }}</span>
                    <div class="text-xs text-ink-3">{{ t('chart.moonRange') }}</div>
                </td>

                <template v-else>
                    <td>
                        <span :class="signCell(row.longitude).color" class="mr-1.5 text-base" aria-hidden="true">{{
                            signCell(row.longitude).glyph
                        }}</span>
                        <span :class="{ 'sr-only': compact }">{{ signCell(row.longitude).name }}</span>
                    </td>
                    <td class="font-mono whitespace-nowrap">{{ formatDegrees(row.longitude) }}</td>
                </template>

                <td v-if="hasHouses" class="text-right font-mono text-ink-2">{{ row.house }}</td>

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

            <tr v-for="row in angleRows" :key="row.key" class="cursor-default!">
                <th scope="row" class="font-normal">
                    <span
                        class="mr-2 inline-block w-4 text-center font-mono text-[10px] text-ink-2"
                        aria-hidden="true"
                        >{{ t(`chart.angleAbbr.${row.key}`) }}</span
                    >
                    {{ t(`chart.angles.${row.key}`) }}
                </th>
                <td>
                    <span :class="signCell(row.longitude).color" class="mr-1.5 text-base" aria-hidden="true">{{
                        signCell(row.longitude).glyph
                    }}</span>
                    <span :class="{ 'sr-only': compact }">{{ signCell(row.longitude).name }}</span>
                </td>
                <td class="font-mono whitespace-nowrap">{{ formatDegrees(row.longitude) }}</td>
                <td class="text-right font-mono text-ink-2">{{ row.house }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</template>
