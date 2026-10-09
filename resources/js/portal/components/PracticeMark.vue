<script setup>
import { computed } from 'vue';

import { initials } from '@/lib/format';

/**
 * The practice's logo, or its initials on its colour when it has none
 * (docs/spec/12: display name, logo and one colour).
 */
const props = defineProps({
    practice: { type: Object, default: null },
    size: { type: String, default: 'md' },
});

const letters = computed(() => initials(props.practice?.name ?? ''));
</script>

<template>
    <img
        v-if="practice?.logo_url"
        :src="practice.logo_url"
        :alt="practice.name"
        class="practice-logo"
        :class="`is-${size}`"
    />
    <span v-else class="practice-initials" :class="`is-${size}`" aria-hidden="true">{{ letters }}</span>
</template>
