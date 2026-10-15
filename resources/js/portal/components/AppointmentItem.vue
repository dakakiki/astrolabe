<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { statusBadge } from '@/portal/lib/booking';
import { appointmentWhen, isLink, zoneLabel } from '@/portal/lib/portal';

/**
 * One appointment as the client sees it: day and hours on their clock with
 * the zone named, the service, online or in person, and — while it is ahead
 * and confirmed — the link or the place. A request waiting for the practice
 * says so (Phase 9b); one the practice did not confirm shows the message it
 * sent with the answer.
 */
const props = defineProps({
    appointment: { type: Object, required: true },
    timeZone: { type: String, required: true },
});

const { t, locale } = useI18n();

const when = computed(() => appointmentWhen(props.appointment, locale.value, props.timeZone));
const zone = computed(() => zoneLabel(props.timeZone, props.appointment.starts_at, locale.value));
const badge = computed(() => statusBadge(props.appointment));
</script>

<template>
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <div class="font-medium">{{ when.date }}</div>
            <span v-if="badge" class="badge" :class="badge.tone">{{
                t(`portal.appointments.status.${badge.key}`)
            }}</span>
        </div>
        <div class="mt-0.5 font-mono text-sm">
            {{ when.time }} <span class="font-sans text-xs text-ink-3">· {{ zone }}</span>
        </div>
        <div class="mt-1.5 text-sm text-ink-2">
            <span v-if="appointment.service">{{ appointment.service }} · </span>
            <span>{{ t(`portal.appointments.location.${appointment.location_type}`) }}</span>
            <span class="text-ink-3">
                · {{ t('portal.appointments.minutes', { count: appointment.duration_minutes }) }}</span
            >
        </div>
        <div v-if="appointment.location_details" class="mt-1.5 text-sm break-words">
            <a
                v-if="isLink(appointment.location_details)"
                :href="appointment.location_details"
                target="_blank"
                rel="noopener noreferrer"
                >{{ t('portal.appointments.join') }}</a
            >
            <span v-else>{{ appointment.location_details }}</span>
        </div>
        <p
            v-if="appointment.declined && appointment.decline_reason"
            class="mt-1.5 text-sm break-words whitespace-pre-line text-ink-2"
        >
            {{ t('portal.appointments.declineReason', { reason: appointment.decline_reason }) }}
        </p>
    </div>
</template>
