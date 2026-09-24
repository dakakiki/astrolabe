<script setup>
import { useI18n } from 'vue-i18n';

defineProps({ status: { type: String, required: true } });

const { t } = useI18n();

// Colour is never the only signal (doc 09): the label is always shown.
const tone = { draft: '', scheduled: 'b-info', completed: 'b-ok', cancelled: 'b-warn', no_show: 'b-danger' };
</script>

<template>
    <span class="badge" :class="tone[status]">{{ t(`consultationStatuses.${status}`) }}</span>
</template>
