<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { formatDegrees, splitLongitude } from '@/lib/zodiac';

/**
 * The twelve house cusps, in two columns of six: houses 1–6 opposite 7–12. `compact` (the
 * consultation sidebar) packs the cells tighter and puts all twelve in one column, so the
 * table never scrolls sideways there.
 */
const props = defineProps({
    cusps: { type: Array, required: true },
    compact: { type: Boolean, default: false },
});

const { t } = useI18n();

const elementColor = { fire: 'text-fire', earth: 'text-earth', air: 'text-air', water: 'text-water' };

const rows = computed(() =>
    props.compact ? [...Array(12).keys()].map((i) => [cell(i)]) : [0, 1, 2, 3, 4, 5].map((i) => [cell(i), cell(i + 6)]),
);

function cell(index) {
    const longitude = props.cusps[index];
    const { sign } = splitLongitude(longitude);

    return {
        number: index + 1,
        degrees: formatDegrees(longitude),
        glyph: sign.glyph,
        name: t(`signs.${sign.key}`),
        color: elementColor[sign.element],
    };
}
</script>

<template>
    <table class="data" :class="{ dense: compact }">
        <caption class="sr-only">
            {{
                t('chart.cusps.title')
            }}
        </caption>
        <thead class="sr-only">
            <tr>
                <template v-for="column in compact ? 1 : 2" :key="column">
                    <th scope="col">{{ t('chart.cusps.house') }}</th>
                    <th scope="col">{{ t('chart.cusps.cusp') }}</th>
                </template>
            </tr>
        </thead>
        <tbody>
            <tr v-for="(pair, i) in rows" :key="i" class="cursor-default!">
                <template v-for="house in pair" :key="house.number">
                    <th scope="row" class="w-8 font-mono text-ink-3!">{{ house.number }}</th>
                    <td class="whitespace-nowrap">
                        <span class="font-mono">{{ house.degrees }}</span>
                        <span :class="house.color" class="mx-1.5" aria-hidden="true">{{ house.glyph }}</span>
                        <span class="text-ink-3">{{ house.name }}</span>
                    </td>
                </template>
            </tr>
        </tbody>
    </table>
</template>
