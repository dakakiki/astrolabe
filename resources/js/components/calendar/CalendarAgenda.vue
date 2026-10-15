<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import AppointmentStatusBadge from '@/components/calendar/AppointmentStatusBadge.vue';
import { useLabels } from '@/composables/useLabels';
import { bookedInPortal, groupByDay, wallClock } from '@/lib/calendar';
import { formatDate, initials } from '@/lib/format';
import { serviceColorClass } from '@/lib/services';

/**
 * The agenda: appointments as a list by day. The default on a phone and the
 * view that reads best with a screen reader (docs/spec/10). What the client
 * booked in the portal says so under the service (Phase 9b).
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
                        <td class="font-mono text-xs whitespace-nowrap sm:w-32">
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
                                    <span v-if="bookedInPortal(appointment)" class="block text-xs text-ink-3">
                                        <span class="cal-src" aria-hidden="true">◇</span>{{ t('calendar.portal') }}
                                    </span>
                                    <!-- On a phone the marks sit here, so a long status never widens the row. -->
                                    <span class="mt-1 flex flex-wrap items-center gap-1 sm:hidden">
                                        <span
                                            v-if="conflicts.has(appointment.id)"
                                            class="badge b-warn"
                                            :title="t('calendar.conflict')"
                                            ><span aria-hidden="true">⚠</span
                                            ><span class="sr-only">{{ t('calendar.conflict') }}</span></span
                                        >
                                        <AppointmentStatusBadge :status="appointment.status" />
                                    </span>
                                </span>
                            </div>
                        </td>
                        <!-- On a phone the detail says where; the row keeps to the screen. -->
                        <td class="hidden text-xs text-ink-3 sm:table-cell">
                            {{ labels.locationType(appointment.location_type) }}
                            <div v-if="appointment.timezone !== timeZone" class="font-mono">
                                {{ appointment.timezone }}
                            </div>
                        </td>
                        <td class="hidden text-right whitespace-nowrap sm:table-cell">
                            <span
                                v-if="conflicts.has(appointment.id)"
                                class="badge b-warn mr-1"
                                :title="t('calendar.conflict')"
                                ><span aria-hidden="true">⚠</span
                                ><span class="sr-only">{{ t('calendar.conflict') }}</span></span
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
