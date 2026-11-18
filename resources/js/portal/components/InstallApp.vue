<script setup>
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';
import InstallGuide from '@/portal/components/InstallGuide.vue';
import { usePwaStore } from '@/portal/stores/pwa';

/**
 * Installing the portal on this device (Phase 9c, docs/spec/12, "Instalacija"): the install bar
 * (InstallGuide — "Install app", then the Android and iOS steps). `place` "profile" shows every
 * state in Profile's "App" card; "home" is Home's discreet offer, which "Not now" hides for good
 * on this browser.
 */
defineProps({
    place: { type: String, default: 'profile' },
});

const { t } = useI18n();
const pwa = usePwaStore();
</script>

<template>
    <div v-if="place === 'home'" class="space-y-3">
        <div class="text-sm">
            <div class="font-medium">{{ t('portal.pwa.install.homeTitle') }}</div>
            <p class="text-ink-3">{{ t('portal.pwa.install.lead') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <InstallGuide />
            <button
                type="button"
                class="btn btn-ghost btn-sm ml-auto"
                @click="pwa.dismissInstallHint()"
                v-tip="t('tips.portal.pwa.install.notNow')"
            >
                <AppIcon name="close" />
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
        <template v-else>
            <p class="mb-3 text-ink-2">{{ t('portal.pwa.install.lead') }}</p>
            <InstallGuide />
        </template>
    </div>
</template>
