<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { groupByDay, wallClock } from '@/lib/calendar';
import { serviceEdgeClass } from '@/lib/services';

/**
 * The month view: six weeks from Monday, up to three appointments a day and a
 * count of the rest. A day opens in the day view.
 */
const props = defineProps({
    days: { type: Array, required: true },
    /** Any day of the month shown; days of the months around it are dimmed. */
    month: { type: String, required: true },
    appointments: { type: Array, required: true },
    timeZone: { type: String, required: true },
    today: { type: String, required: true },
    conflicts: { type: Set, default: () => new Set() },
    selectedId: { type: Number, default: null },
});
const emit = defineEmits(['select', 'open-day']);

const { t, locale } = useI18n();

const SHOWN = 3;

const byDay = computed(() =>
    Object.fromEntries(groupByDay(props.appointments, props.timeZone).map((group) => [group.date, group.appointments])),
);

const weekdays = computed(() =>
    props.days
        .slice(0, 7)
        .map((day) =>
            new Intl.DateTimeFormat(locale.value, { weekday: 'short', timeZone: 'UTC' }).format(
                new Date(`${day}T00:00:00Z`),
            ),
        ),
);

const pad = (value) => String(value).padStart(2, '0');
const clock = (appointment) => {
    const { minutes } = wallClock(appointment.starts_at, props.timeZone);
    return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;
};
</script>

<template>
    <div class="cal">
        <div class="cal-head" style="grid-template-columns: repeat(7, minmax(0, 1fr))">
            <div v-for="name in weekdays" :key="name">{{ name }}</div>
        </div>
        <div class="cal-month">
            <div
                v-for="day in days"
                :key="day"
                class="cal-day"
                :class="{ 'is-outside': day.slice(0, 7) !== month.slice(0, 7), 'is-today': day === today }"
            >
                <button type="button" class="day-num" @click="emit('open-day', day)">{{ Number(day.slice(8)) }}</button>
                <button
                    v-for="appointment in (byDay[day] ?? []).slice(0, SHOWN)"
                    :key="appointment.id"
                    type="button"
                    class="cal-ev"
                    :class="[
                        serviceEdgeClass(appointment.service?.color),
                        {
                            'is-cancelled': appointment.status === 'cancelled',
                            'is-conflict': conflicts.has(appointment.id),
                            'is-selected': selectedId === appointment.id,
                        },
                    ]"
                    @click="emit('select', appointment)"
                >
                    <span class="n">{{ clock(appointment) }} {{ appointment.client?.full_name }}</span>
                </button>
                <button
                    v-if="(byDay[day]?.length ?? 0) > SHOWN"
                    type="button"
                    class="text-left text-xs text-link hover:underline"
                    @click="emit('open-day', day)"
                >
                    {{ t('calendar.more', { n: byDay[day].length - SHOWN }) }}
                </button>
            </div>
        </div>
    </div>
</template>
