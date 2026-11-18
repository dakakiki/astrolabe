<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * An appointment's status as text next to a colour, never colour alone (docs/spec/10).
 * A booking request from the portal waits for the astrologer (Phase 9b): a warning tone —
 * and so does a confirmed appointment the client asked to move (`moveRequested`, docs/spec/12).
 */
const props = defineProps({
    status: { type: String, required: true },
    moveRequested: { type: Boolean, default: false },
});

const { t } = useI18n();

const TONES = { requested: 'b-warn', scheduled: 'b-info', completed: 'b-ok', no_show: 'b-warn', cancelled: '' };

const moving = computed(() => props.moveRequested && props.status === 'scheduled');
const tone = computed(() => (moving.value ? 'b-warn' : (TONES[props.status] ?? '')));
</script>

<template>
    <span class="badge" :class="tone">{{
        moving ? t('appointments.moveRequestedBadge') : t(`appointmentStatuses.${status}`)
    }}</span>
</template>
