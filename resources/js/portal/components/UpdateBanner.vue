<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { usePwaStore } from '@/portal/stores/pwa';

/**
 * "A new version is available — Reload" (Phase 9c, docs/spec/12): the new
 * service worker waits until the person presses Reload, so nothing changes
 * under them mid-booking. Also shown when a screen's file could not be loaded
 * (the portal was updated meanwhile); Reload then just reloads.
 */
const { t } = useI18n();
const pwa = usePwaStore();
const busy = ref(false);

function reload() {
    busy.value = true;
    pwa.applyUpdate();
}
</script>

<template>
    <div role="status" aria-live="polite">
        <template v-if="pwa.updateReady">
            <!-- Room below the page, so its foot can still scroll above the banner. -->
            <div class="h-16" aria-hidden="true" />
            <div class="fixed inset-x-0 bottom-0 z-[90] border-t border-line bg-surface shadow-card">
                <div
                    class="mx-auto flex w-full max-w-[880px] items-center gap-3 px-4 pt-3 pb-[max(12px,env(safe-area-inset-bottom))]"
                >
                    <span class="min-w-0 flex-1 text-sm">{{ t('portal.pwa.update.available') }}</span>
                    <button type="button" class="btn btn-primary btn-sm shrink-0" :disabled="busy" @click="reload">
                        {{ t('portal.pwa.update.reload') }}
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
