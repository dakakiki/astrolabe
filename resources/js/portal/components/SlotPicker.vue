<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { dayLabel, dayOf, dayZoneMoment, groupSlotsByDay, nextDay, slotLabels } from '@/portal/lib/booking';
import { zoneLabel } from '@/portal/lib/portal';

/**
 * Free times to choose from (docs/spec/12, "Zakazivanje (9b)"): the days
 * that have any as a strip, the chosen day's times as buttons, all on the
 * client's clock with the zone named for that day (it may change with
 * daylight saving inside the window — and within the day the clocks go back,
 * when the hour that happens twice names its zone on both buttons). The
 * chosen time is the v-model — the UTC moment the server sent.
 */
const props = defineProps({
    slots: { type: Array, required: true },
    timeZone: { type: String, required: true },
    // How far ahead the practice books, for "No free times in the next N days".
    days: { type: Number, required: true },
});

const selected = defineModel({ type: String, default: null });

const { t, locale } = useI18n();

const groups = computed(() => groupSlotsByDay(props.slots, props.timeZone));
const day = ref(nextDay(groups.value, selected.value ? dayOf(selected.value, props.timeZone) : null));
const current = computed(() => groups.value.find((group) => group.date === day.value) ?? null);
const labels = computed(() => slotLabels(current.value?.slots ?? [], locale.value, props.timeZone));
// The night the clocks go back: the zone goes under the time, and the day's buttons grow alike.
const twice = computed(() => labels.value.some((item) => item.zone));
const zone = computed(() =>
    zoneLabel(props.timeZone, dayZoneMoment(current.value?.slots, selected.value), locale.value),
);
const strip = ref(null);

// A new list (a refresh after a time was taken): stay on the day, or the next one that has times.
watch(groups, (value) => {
    day.value = nextDay(value, day.value);
});

watch(day, () => nextTick(showDay));

function label(date) {
    return dayLabel(date, locale.value);
}

function isSelected(slot) {
    return selected.value !== null && Date.parse(slot.starts_at) === Date.parse(selected.value);
}

/** Keep the chosen day in view inside the strip without moving the page. */
function showDay() {
    const button = strip.value?.querySelector('[aria-pressed="true"]');
    if (!strip.value || !button) return;

    const left = button.offsetLeft;
    const right = left + button.offsetWidth;

    if (left < strip.value.scrollLeft || right > strip.value.scrollLeft + strip.value.clientWidth) {
        strip.value.scrollLeft = Math.max(0, left - 8);
    }
}

onMounted(showDay);
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span class="text-sm text-ink-2">{{ t('portal.appointments.zone', { zone }) }}</span>
            <RouterLink :to="{ name: 'profile' }" class="text-xs">{{ t('portal.booking.changeZone') }}</RouterLink>
        </div>

        <div v-if="groups.length === 0" class="empty">
            <p class="font-medium text-ink-2">{{ t('portal.booking.noTimes', { count: days }, days) }}</p>
            <p class="mt-1 text-sm">{{ t('portal.booking.noTimesHint') }}</p>
        </div>

        <template v-else>
            <div ref="strip" class="day-strip" role="group" :aria-label="t('portal.booking.days')">
                <button
                    v-for="group in groups"
                    :key="group.date"
                    type="button"
                    class="day-btn"
                    :aria-pressed="group.date === day ? 'true' : 'false'"
                    :aria-label="`${label(group.date).full} · ${t('portal.booking.dayCount', { count: group.slots.length }, group.slots.length)}`"
                    @click="day = group.date"
                >
                    <span class="d-wd">{{ label(group.date).weekday }}</span>
                    <span class="d-num">{{ label(group.date).day }}</span>
                    <span class="d-mon">{{ label(group.date).month }}</span>
                    <span class="d-count">{{
                        t('portal.booking.dayCount', { count: group.slots.length }, group.slots.length)
                    }}</span>
                </button>
            </div>

            <template v-if="current">
                <h3 :id="`times-${current.date}`" class="mt-4 mb-2 text-sm font-medium">
                    {{ label(current.date).full }}
                </h3>
                <div class="slot-grid" role="group" :aria-labelledby="`times-${current.date}`">
                    <button
                        v-for="(slot, index) in current.slots"
                        :key="slot.starts_at"
                        type="button"
                        class="btn btn-sm"
                        :class="{ 'btn-primary': isSelected(slot), 'h-auto min-h-7 flex-col gap-0 py-1': twice }"
                        :aria-pressed="isSelected(slot) ? 'true' : 'false'"
                        :aria-label="labels[index].zone ? `${labels[index].time} ${labels[index].zone}` : undefined"
                        @click="selected = slot.starts_at"
                    >
                        {{ labels[index].time }}
                        <span v-if="labels[index].zone" class="text-[11px] leading-tight font-normal">{{
                            labels[index].zone
                        }}</span>
                    </button>
                </div>
            </template>
        </template>
    </div>
</template>
