<script setup>
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterView, useRoute } from 'vue-router';

import ToastHost from '@/components/ToastHost.vue';
import { brandPalette, paletteCss } from '@/lib/brand';
import { setLocale } from '@/portal/i18n';
import PortalAuthLayout from '@/portal/layouts/PortalAuthLayout.vue';
import PortalLayout from '@/portal/layouts/PortalLayout.vue';
import { useSessionStore } from '@/portal/stores/session';
import { useThemeStore } from '@/stores/theme';

const { t } = useI18n();
const route = useRoute();
const session = useSessionStore();
useThemeStore();

const layout = computed(() => (route.meta.layout === 'auth' ? PortalAuthLayout : PortalLayout));

// The open practice's colour over the --brand* tokens, for both themes, and its name in the tab —
// or, before any is open, the colour of the practice a page shows (the invitation).
let style = null;

watch(
    () => session.brandedPractice,
    (practice) => {
        style ??= document.head.appendChild(Object.assign(document.createElement('style'), { id: 'practice-brand' }));
        style.textContent = paletteCss(brandPalette(practice?.brand_color));
        document.title = practice ? `${practice.name} · ${t('portal.title')}` : t('portal.title');
    },
    { immediate: true },
);

watch(() => session.user?.locale, setLocale, { immediate: true });
</script>

<template>
    <component :is="layout">
        <RouterView />
    </component>
    <ToastHost />
</template>
