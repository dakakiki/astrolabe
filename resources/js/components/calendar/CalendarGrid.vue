<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { layoutDay, slotStart, visibleHours, wallClock } from '@/lib/calendar';
import { formatDate } from '@/lib/format';
import { serviceEdgeClass } from '@/lib/services';

/**
 * The day and week views: a column per day, a row per half hour, appointments
 * placed by their time on the astrologer's clock. Overlapping appointments
 * stand side by side; clicking an empty half hour starts a new one there.
 */
const props = defineProps({
    days: { type: Array, required: true },
    appointments: { type: Array, required: true },
    timeZone: { type: String, required: true },
    today: { type: String, required: true },
    /** Minutes since midnight now, in the same zone, for the red line. */
    nowMinutes: { type: Number, default: null },
    conflicts: { type: Set, default: () => new Set() },
    selectedId: { type: Number, default: null },
});
const emit = defineEmits(['select', 'create']);

const { t, locale } = useI18n();

const HOUR = 48;

const layouts = computed(() =>
    Object.fromEntries(props.days.map((day) => [day, layoutDay(props.appointments, day, props.timeZone)])),
);

const hours = computed(() => {
    const [from, to] = visibleHours(Object.values(layouts.value).flat());
    return { from, to, list: Array.from({ length: to - from }, (_, index) => from + index) };
});

const slots = computed(() =>
    Array.from({ length: hours.value.list.length * 2 }, (_, index) => hours.value.from * 60 + index * 30),
);

const pad = (value) => String(value).padStart(2, '0');
const clock = (minutes) => `${pad(Math.floor(minutes / 60) % 24)}:${pad(minutes % 60)}`;

const dayFormat = (options) => (day) =>
    new Intl.DateTimeFormat(locale.value, { ...options, timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`));
const weekdayName = dayFormat({ weekday: 'short' });
const dayLabel = dayFormat({ day: 'numeric', month: 'short' });

function position(item) {
    const top = ((item.start - hours.value.from * 60) / 60) * HOUR;
    const height = Math.max(((item.end - item.start) / 60) * HOUR - 2, 18);
    const width = 100 / item.columns;

    return {
        top: `${top}px`,
        height: `${height}px`,
        left: `calc(${item.column * width}% + 2px)`,
        width: `calc(${width}% - 4px)`,
    };
}

const nowTop = computed(() => {
    if (props.nowMinutes === null || !props.days.includes(props.today)) return null;
    const minutes = props.nowMinutes - hours.value.from * 60;
    if (minutes < 0 || minutes > hours.value.list.length * 60) return null;

    return `${(minutes / 60) * HOUR}px`;
});

function label(item) {
    const appointment = item.appointment;
    const start = wallClock(appointment.starts_at, props.timeZone);

    return [
        clock(start.minutes),
        appointment.client?.full_name,
        appointment.service?.name,
        t(`appointmentStatuses.${appointment.status}`),
    ]
        .filter(Boolean)
        .join(', ');
}
</script>

<template>
    <div class="cal">
        <div class="cal-scroll">
            <div :style="{ '--cal-days': days.length, '--cal-hour': `${HOUR}px` }">
                <div class="cal-head">
                    <div />
                    <div v-for="day in days" :key="day" :class="{ today: day === today }">
                        {{ weekdayName(day) }}<strong>{{ dayLabel(day) }}</strong>
                    </div>
                </div>
                <div class="cal-grid">
                    <div class="cal-hours" aria-hidden="true">
                        <div v-for="hour in hours.list" :key="hour">{{ pad(hour) }}:00</div>
                    </div>
                    <div v-for="day in days" :key="day" class="cal-col">
                        <button
                            v-for="minutes in slots"
                            :key="minutes"
                            type="button"
                            class="cal-slot"
                            :aria-label="
                                t('calendar.slot', { date: formatDate(day, locale, 'medium'), time: clock(minutes) })
                            "
                            @click="emit('create', slotStart(day, minutes))"
                        />
                        <button
                            v-for="item in layouts[day]"
                            :key="item.appointment.id"
                            type="button"
                            class="cal-ev"
                            :class="[
                                serviceEdgeClass(item.appointment.service?.color),
                                {
                                    'is-cancelled': item.appointment.status === 'cancelled',
                                    'is-conflict': conflicts.has(item.appointment.id),
                                    'is-selected': selectedId === item.appointment.id,
                                },
                            ]"
                            :style="position(item)"
                            :aria-label="label(item)"
                            :title="conflicts.has(item.appointment.id) ? t('calendar.conflict') : undefined"
                            @click="emit('select', item.appointment)"
                        >
                            <span class="n"
                                ><template v-if="conflicts.has(item.appointment.id)">⚠ </template
                                >{{ item.appointment.client?.full_name }}</span
                            >
                            <span class="t"
                                >{{ clock(wallClock(item.appointment.starts_at, timeZone).minutes) }}
                                <template v-if="item.appointment.service">
                                    · {{ item.appointment.service.name }}</template
                                >
                                <template v-if="item.appointment.status !== 'scheduled'">
                                    · {{ t(`appointmentStatuses.${item.appointment.status}`) }}</template
                                ></span
                            >
                        </button>
                        <div v-if="day === today && nowTop" class="cal-now" :style="{ top: nowTop }" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
