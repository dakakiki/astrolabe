<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import AppointmentStatusBadge from '@/components/calendar/AppointmentStatusBadge.vue';
import { useLabels } from '@/composables/useLabels';
import { groupByDay, wallClock } from '@/lib/calendar';
import { formatDate, initials } from '@/lib/format';
import { serviceColorClass } from '@/lib/services';

/**
 * The agenda: appointments as a list by day. The default on a phone and the
 * view that reads best with a screen reader (docs/spec/10).
 */
const props = defineProps({
    appointments: { type: Array, required: true },
    timeZone: { type: String, required: true },
    today: { type: String, required: true },
    conflicts: { type: Set, default: () => new Set() },
    selectedId: { type: Number, default: null },
});
const emit = defineEmits(['select']);

const { t, locale } = useI18n();
const labels = useLabels();

const groups = computed(() => groupByDay(props.appointments, props.timeZone));

const pad = (value) => String(value).padStart(2, '0');
function span(appointment) {
    const start = wallClock(appointment.starts_at, props.timeZone).minutes;
    const end = wallClock(appointment.ends_at, props.timeZone).minutes;
    const clock = (minutes) => `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;

    return `${clock(start)}–${clock(end)}`;
}
</script>

<template>
    <section class="card">
        <div v-for="group in groups" :key="group.date">
            <h2
                class="border-b border-line-soft bg-surface-2 px-4 py-2 text-xs font-semibold tracking-wider text-ink-3 uppercase"
                :class="{ 'text-link!': group.date === today }"
            >
                {{ formatDate(group.date, locale, 'full') }}
            </h2>
            <table class="data">
                <tbody>
                    <tr
                        v-for="appointment in group.appointments"
                        :key="appointment.id"
                        :class="{ 'bg-brand-050': selectedId === appointment.id }"
                        @click="emit('select', appointment)"
                    >
                        <td class="w-32 font-mono text-xs whitespace-nowrap">
                            <button type="button" class="hover:underline" @click.stop="emit('select', appointment)">
                                {{ span(appointment) }}
                            </button>
                        </td>
                        <td>
                            <div class="person">
                                <span class="ini" aria-hidden="true">{{
                                    initials(appointment.client?.full_name)
                                }}</span>
                                <span class="min-w-0">
                                    <span
                                        class="block font-medium"
                                        :class="{ 'line-through': appointment.status === 'cancelled' }"
                                    >
                                        {{ appointment.client?.full_name }}
                                    </span>
                                    <span
                                        v-if="appointment.service"
                                        class="flex items-center gap-1.5 text-xs text-ink-3"
                                    >
                                        <span
                                            class="inline-block size-2 rounded-sm"
                                            :class="serviceColorClass(appointment.service.color)"
                                            aria-hidden="true"
                                        />{{ appointment.service.name }} ·
                                        {{ t('calendar.minutes', { n: appointment.duration_minutes }) }}
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td class="text-xs text-ink-3">
                            {{ labels.locationType(appointment.location_type) }}
                            <div v-if="appointment.timezone !== timeZone" class="font-mono">
                                {{ appointment.timezone }}
                            </div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <span
                                v-if="conflicts.has(appointment.id)"
                                class="badge b-warn mr-1"
                                :title="t('calendar.conflict')"
                                >⚠</span
                            >
                            <AppointmentStatusBadge :status="appointment.status" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!groups.length" class="empty">
            <div class="e-glyph" aria-hidden="true">◌</div>
            <h3>{{ t('calendar.empty') }}</h3>
            <p class="text-ink-3">{{ t('calendar.emptyHint') }}</p>
        </div>
    </section>
</template>
