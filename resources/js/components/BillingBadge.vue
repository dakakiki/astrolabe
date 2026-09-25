<script setup>
import { useI18n } from 'vue-i18n';

import { BILLING_TONES } from '@/lib/payments';

/** Where a consultation's fee stands; nothing when no fee is set and nothing was paid. */
defineProps({ status: { type: String, default: null } });

const { t } = useI18n();
</script>

<template>
    <span v-if="status" class="badge" :class="BILLING_TONES[status]">{{ t(`billing.statuses.${status}`) }}</span>
</template>
