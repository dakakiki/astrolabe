<script setup>
import { useI18n } from 'vue-i18n';

defineProps({ status: { type: String, required: true } });

const { t } = useI18n();

// Colour is never the only signal (doc 09): the label is always shown.
const tone = { lead: 'b-info', active: 'b-ok', inactive: '', archived: 'b-warn' };
</script>

<template>
    <span class="badge" :class="tone[status]">{{ t(`statuses.${status}`) }}</span>
</template>
