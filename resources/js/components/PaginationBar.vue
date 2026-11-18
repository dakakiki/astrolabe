<script setup>
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';

defineProps({ meta: { type: Object, required: true } });
const emit = defineEmits(['page']);

const { t } = useI18n();
</script>

<template>
    <div class="flex items-center justify-between gap-3 border-t border-line-soft px-4 py-3 text-xs text-ink-3">
        <span>{{ t('clients.pagination', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</span>
        <div class="flex gap-2">
            <button
                type="button"
                class="btn btn-sm"
                :disabled="meta.current_page <= 1"
                @click="emit('page', meta.current_page - 1)"
                v-tip="t('tips.clients.previous')"
            >
                <AppIcon name="previous" />
                {{ t('clients.previous') }}
            </button>
            <button
                type="button"
                class="btn btn-sm"
                :disabled="meta.current_page >= meta.last_page"
                @click="emit('page', meta.current_page + 1)"
                v-tip="t('tips.clients.next')"
            >
                {{ t('clients.next') }}<AppIcon name="next" />
            </button>
        </div>
    </div>
</template>
