<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/** An appointment's status as text next to a colour, never colour alone (docs/spec/10). */
const props = defineProps({ status: { type: String, required: true } });

const { t } = useI18n();

const tone = computed(
    () => ({ scheduled: 'b-info', completed: 'b-ok', no_show: 'b-warn', cancelled: '' })[props.status] ?? '',
);
</script>

<template>
    <span class="badge" :class="tone">{{ t(`appointmentStatuses.${status}`) }}</span>
</template>
