<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { usePwaStore } from '@/portal/stores/pwa';

/**
 * Installing the portal on this device (Phase 9c, docs/spec/12, "Instalacija"):
 * the browser's own dialog where Chrome and Edge offer one, the Share → Add to
 * Home Screen steps on iPhone and iPad, a one-line hint elsewhere. `place`
 * "profile" shows every state in Profile's "App" card; "home" is Home's
 * discreet offer, which "Not now" hides for good on this browser.
 */
defineProps({
    place: { type: String, default: 'profile' },
});

const { t } = useI18n();
const pwa = usePwaStore();
const busy = ref(false);

async function install() {
    busy.value = true;

    try {
        await pwa.promptInstall();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="place === 'home'" class="flex flex-wrap items-center gap-3">
        <div class="min-w-0 flex-1 basis-60 text-sm">
            <div class="font-medium">{{ t('portal.pwa.install.homeTitle') }}</div>
            <p class="text-ink-3">
                {{ pwa.installState === 'ios' ? t('portal.pwa.install.iosSteps') : t('portal.pwa.install.lead') }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button
                v-if="pwa.installState === 'prompt'"
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy"
                @click="install"
            >
                {{ t('portal.pwa.install.action') }}
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="pwa.dismissInstallHint()">
                {{ t('portal.pwa.install.notNow') }}
            </button>
        </div>
    </div>

    <div v-else class="text-sm">
        <p v-if="pwa.installState === 'standalone'" class="flex flex-wrap items-center gap-2">
            <span class="badge b-ok">{{ t('portal.pwa.install.badge') }}</span>
            {{ t('portal.pwa.install.standalone') }}
        </p>
        <p v-else-if="pwa.installState === 'installed'">{{ t('portal.pwa.install.installed') }}</p>
        <template v-else-if="pwa.installState === 'prompt'">
            <p class="mb-3 text-ink-2">{{ t('portal.pwa.install.lead') }}</p>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="install">
                {{ t('portal.pwa.install.action') }}
            </button>
        </template>
        <template v-else-if="pwa.installState === 'ios'">
            <p class="text-ink-2">{{ t('portal.pwa.install.lead') }} {{ t('portal.pwa.install.iosSteps') }}</p>
            <p class="mt-2 text-xs text-ink-3">{{ t('portal.pwa.install.iosNote') }}</p>
        </template>
        <p v-else class="text-ink-3">{{ t('portal.pwa.install.hint') }}</p>
    </div>
</template>
