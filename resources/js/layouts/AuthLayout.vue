<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import BrandMark from '@/components/BrandMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import WheelArt from '@/components/WheelArt.vue';
import { LEGAL_DOCUMENTS } from '@/lib/legal';

const { t } = useI18n();
</script>

<template>
    <div class="auth">
        <div class="auth-art">
            <WheelArt />
            <div class="kicker">{{ t('auth.art.kicker') }}</div>
            <h1>{{ t('auth.art.title') }}</h1>
            <p>{{ t('auth.art.body') }}</p>
        </div>
        <main class="auth-form">
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-2 font-serif text-lg text-ink">
                    <BrandMark class="size-8" />
                    {{ t('app.name') }}
                </div>
                <ThemeToggle />
            </div>
            <slot />
            <nav class="legal-links mt-8" :aria-label="t('legal.title')">
                <RouterLink
                    v-for="slug in LEGAL_DOCUMENTS"
                    :key="slug"
                    :to="{ name: 'legal.show', params: { document: slug } }"
                    >{{ t(`legal.documents.${slug}`) }}</RouterLink
                >
            </nav>
        </main>
    </div>
</template>
