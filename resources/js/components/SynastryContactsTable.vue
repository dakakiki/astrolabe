<script setup>
import { useI18n } from 'vue-i18n';

import { useLabels } from '@/composables/useLabels';
import { ASPECT_GLYPHS, formatOrb } from '@/lib/chart';
import { BODY_GLYPHS } from '@/lib/zodiac';

/**
 * Contacts between two charts (Phase 7e): the other person's point, the
 * aspect, the client's point and the orb. Both charts stand still, so there
 * is no applying or separating.
 */
defineProps({
    /** [{ a, b, type, orb }]: `a` the other person's point, `b` the client's. */
    contacts: { type: Array, required: true },
    otherName: { type: String, required: true },
    clientName: { type: String, required: true },
    caption: { type: String, required: true },
});

const { t } = useI18n();
const labels = useLabels();

const glyph = (key) => BODY_GLYPHS[key] ?? t(`chart.angleAbbr.${key}`);
const isAngle = (key) => !BODY_GLYPHS[key];
</script>

<template>
    <table class="data">
        <caption class="sr-only">
            {{
                caption
            }}
        </caption>
        <thead>
            <tr>
                <th scope="col">{{ otherName }}</th>
                <th scope="col">{{ t('chart.aspects.aspect') }}</th>
                <th scope="col">{{ clientName }}</th>
                <th scope="col" class="text-right">{{ t('synastry.contacts.orb') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="contact in contacts" :key="`${contact.a}-${contact.b}`" class="cursor-default!">
                <th scope="row" class="font-normal whitespace-nowrap">
                    <span
                        class="mr-1.5 inline-block w-4 text-center text-link"
                        :class="isAngle(contact.a) ? 'font-mono text-[10px]' : 'text-base'"
                        aria-hidden="true"
                        >{{ glyph(contact.a) }}</span
                    >{{ labels.point(contact.a) }}
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
                        :class="isAngle(contact.b) ? 'font-mono text-[10px]' : 'text-base'"
                        aria-hidden="true"
                        >{{ glyph(contact.b) }}</span
                    >{{ labels.point(contact.b) }}
                </td>
                <td class="text-right font-mono whitespace-nowrap">{{ formatOrb(contact.orb) }}</td>
            </tr>
        </tbody>
    </table>
</template>
