<script setup>
import { useI18n } from 'vue-i18n';

import BrandMark from '@/components/BrandMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';

/**
 * Signing in, and the pages emailed links open: one card in the middle. A
 * page that knows the practice (the invitation) shows it inside the card.
 */
const { t } = useI18n();
</script>

<template>
    <div class="portal-auth">
        <div class="portal-auth-top">
            <BrandMark class="size-7" />
            <span>{{ t('portal.title') }}</span>
            <span class="ml-auto"><ThemeToggle /></span>
        </div>
        <main class="card portal-auth-card">
            <slot />
        </main>
        <p class="portal-foot">
            <BrandMark class="size-4" />
            {{ t('portal.poweredBy') }}
        </p>
    </div>
</template>
