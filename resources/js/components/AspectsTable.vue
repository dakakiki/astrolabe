<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { ASPECT_GLYPHS, byOrb, formatOrb } from '@/lib/chart';
import { BODY_GLYPHS } from '@/lib/zodiac';

/**
 * The chart's aspects from the tightest to the widest, with the orb and
 * whether each is applying or separating (docs/spec/11). The same list the
 * wheel draws as lines, readable without it.
 */
const props = defineProps({ aspects: { type: Array, required: true } });

const { t } = useI18n();

const rows = computed(() => byOrb(props.aspects));

function pointName(key) {
    return ['asc', 'mc'].includes(key) ? t(`chart.angles.${key}`) : t(`bodies.${key}`);
}

function pointGlyph(key) {
    return BODY_GLYPHS[key] ?? t(`chart.angleAbbr.${key}`);
}
</script>

<template>
    <p v-if="!rows.length" class="px-4 py-3 text-ink-3">{{ t('chart.aspects.none') }}</p>

    <table v-else class="data">
        <caption class="sr-only">
            {{
                t('chart.aspects.title')
            }}
        </caption>
        <thead>
            <tr>
                <th scope="col">{{ t('chart.aspects.pair') }}</th>
                <th scope="col">{{ t('chart.aspects.aspect') }}</th>
                <th scope="col" class="text-right">{{ t('chart.aspects.orb') }}</th>
                <th scope="col">
                    <span class="sr-only">{{ t('chart.aspects.motion') }}</span>
                </th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="aspect in rows" :key="`${aspect.a}-${aspect.b}`" class="cursor-default!">
                <th scope="row" class="font-normal">
                    <span class="sr-only">{{ pointName(aspect.a) }} – {{ pointName(aspect.b) }}</span>
                    <span aria-hidden="true" :title="`${pointName(aspect.a)} – ${pointName(aspect.b)}`">
                        <span class="inline-block min-w-4 text-center text-base text-ink-2">{{
                            pointGlyph(aspect.a)
                        }}</span>
                        <span class="mx-1 text-ink-4">·</span>
                        <span class="inline-block min-w-4 text-center text-base text-ink-2">{{
                            pointGlyph(aspect.b)
                        }}</span>
                    </span>
                </th>
                <td :class="`asp-${aspect.type}`">
                    <span class="aspect-glyph mr-1.5 text-base" aria-hidden="true">{{
                        ASPECT_GLYPHS[aspect.type]
                    }}</span>
                    {{ t(`aspectTypes.${aspect.type}`) }}
                </td>
                <td class="text-right font-mono whitespace-nowrap">{{ formatOrb(aspect.orb) }}</td>
                <td class="text-xs text-ink-3">
                    <template v-if="aspect.applying !== null">
                        {{ aspect.applying ? t('chart.aspects.applying') : t('chart.aspects.separating') }}
                    </template>
                </td>
            </tr>
        </tbody>
    </table>
</template>
