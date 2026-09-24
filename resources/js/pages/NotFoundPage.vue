<script setup>
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <main class="flex min-h-screen flex-col items-center justify-center gap-4 px-4">
        <h1 class="font-serif text-2xl text-ink">{{ t('notFound.title') }}</h1>
        <RouterLink to="/" class="text-link hover:underline">{{ t('notFound.back') }}</RouterLink>
    </main>
</template>
