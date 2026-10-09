<script setup>
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';

import ToastHost from '@/components/ToastHost.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { layoutOf } from '@/lib/layout';

const route = useRoute();

// 'bare' has no component: the page draws its own header.
const LAYOUTS = { app: AppLayout, auth: AuthLayout, bare: null };
const layout = computed(() => LAYOUTS[layoutOf(route.meta)]);
</script>

<template>
    <component :is="layout" v-if="layout">
        <RouterView />
    </component>
    <RouterView v-else />
    <ToastHost />
</template>
