<script setup>
import { useI18n } from 'vue-i18n';

import ThemeToggle from '@/components/ThemeToggle.vue';
import WheelArt from '@/components/WheelArt.vue';

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
                    <span
                        class="grid size-7 place-items-center rounded-full bg-brand text-sm text-on-brand"
                        aria-hidden="true"
                        >✷</span
                    >
                    {{ t('app.name') }}
                </div>
                <ThemeToggle />
            </div>
            <slot />
        </main>
    </div>
</template>
