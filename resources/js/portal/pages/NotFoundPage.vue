<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import AppIcon from '@/components/AppIcon.vue';

const { t } = useI18n();
</script>

<template>
    <h1>{{ t('portal.notFound.title') }}</h1>
    <p class="sub">{{ t('portal.notFound.body') }}</p>
    <RouterLink
        :to="{ name: 'home' }"
        class="btn btn-primary w-full justify-center"
        v-tip="t('tips.portal.notFound.home')"
        ><AppIcon name="home" />{{ t('portal.notFound.home') }}</RouterLink
    >
</template>
