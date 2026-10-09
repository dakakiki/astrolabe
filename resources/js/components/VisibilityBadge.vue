<script setup>
import { useI18n } from 'vue-i18n';

defineProps({ visibility: { type: String, required: true } });

const { t } = useI18n();

const tone = { private: '', team: 'b-info', shared_with_client: 'b-ok' };
</script>

<template>
    <span class="badge" :class="tone[visibility]" :title="t(`visibility.hints.${visibility}`)">
        {{ visibility === 'shared_with_client' ? t('visibility.inPortal') : t(`visibility.${visibility}`) }}
    </span>
</template>
