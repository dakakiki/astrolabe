<script setup>
/**
 * Formatted text as stored. The HTML was sanitized against an allowlist when it
 * was saved (App\Support\RichText); nothing else may be passed in here.
 */
defineProps({ html: { type: String, default: null } });
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- sanitized on the server before it was stored -->
    <div v-if="html" class="rich" v-html="html" />
</template>
