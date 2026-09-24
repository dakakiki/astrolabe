<script setup>
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';

import ToastHost from '@/components/ToastHost.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const route = useRoute();

const layout = computed(() => ({ auth: AuthLayout, bare: null })[route.meta.layout] ?? AppLayout);
</script>

<template>
    <component :is="layout" v-if="layout">
        <RouterView />
    </component>
    <RouterView v-else />
    <ToastHost />
</template>
