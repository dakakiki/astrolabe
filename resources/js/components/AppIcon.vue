<script setup>
import { icons } from '@/lib/icons';

/**
 * One icon from lib/icons.js, drawn in the current text colour. It is decoration only
 * (aria-hidden): a button keeps its words, and a button with nothing but an icon keeps its
 * aria-label. Sizes come from the surrounding element in components.css (.btn, .nav …).
 */
defineProps({
    name: { type: String, required: true },
});
</script>

<template>
    <component :is="icons[name]" class="icon" aria-hidden="true" />
</template>
