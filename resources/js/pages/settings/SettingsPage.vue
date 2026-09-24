<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, RouterView } from 'vue-router';

const { t } = useI18n();

const sections = ['profile', 'practice', 'chart', 'regional', 'security'];
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('settings.eyebrow') }}</div>
        <h1>{{ t('settings.title') }}</h1>
        <div class="sub">{{ t('settings.sub') }}</div>
    </div>

    <div class="grid gap-4 md:grid-cols-[220px_minmax(0,1fr)]">
        <div>
            <nav class="card subnav p-2" :aria-label="t('settings.title')">
                <RouterLink v-for="section in sections" :key="section" :to="{ name: `settings.${section}` }">
                    {{ t(`settings.sections.${section}`) }}
                </RouterLink>
            </nav>
        </div>
        <div class="max-w-3xl space-y-4">
            <RouterView />
        </div>
    </div>
</template>
