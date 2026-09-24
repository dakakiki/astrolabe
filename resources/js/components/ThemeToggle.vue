<script setup>
import { useI18n } from 'vue-i18n';

import { useThemeStore } from '@/stores/theme';

const { t } = useI18n();
const theme = useThemeStore();
</script>

<template>
    <button
        type="button"
        class="icon-btn"
        :aria-label="theme.theme === 'day' ? t('theme.toNight') : t('theme.toDay')"
        :title="theme.theme === 'day' ? t('theme.toNight') : t('theme.toDay')"
        @click="theme.toggle()"
    >
        <span aria-hidden="true">{{ theme.theme === 'day' ? '☾' : '☀' }}</span>
    </button>
</template>
