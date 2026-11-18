import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { installView, platformOf, showInstallBar } from '@/portal/lib/pwa';
import { appInstalled, canPrompt, promptInstall as openInstallDialog } from '@/portal/pwa/install';
import { applyUpdate as applyWorkerUpdate, updateWaiting } from '@/portal/pwa/serviceWorker';

function runningStandalone() {
    try {
        return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    } catch {
        return false;
    }
}

/**
 * The portal as an app on this device (Phase 9c, docs/spec/12): a new version
 * waiting (or a route's file gone after an update), installing, and which kind
 * of device this is. Read from the browser once; nothing of it is sent anywhere.
 */
export const usePwaStore = defineStore('portal-pwa', () => {
    const stale = ref(false);
    const standalone = ref(runningStandalone());
    const platform = platformOf({
        userAgent: navigator.userAgent,
        maxTouchPoints: navigator.maxTouchPoints,
        standalone: navigator.standalone,
    });

    try {
        window
            .matchMedia('(display-mode: standalone)')
            .addEventListener('change', (event) => (standalone.value = event.matches || navigator.standalone === true));
    } catch {
        // An old browser without display modes: what it said at first stays.
    }

    const updateReady = computed(() => updateWaiting.value || stale.value);
    const canInstall = computed(() => canPrompt.value);
    const installed = computed(() => appInstalled.value);
    const installState = computed(() =>
        installView({
            standalone: standalone.value,
            installed: installed.value,
            canInstall: canInstall.value,
            platform,
        }),
    );
    const showInstall = computed(() =>
        showInstallBar({ view: installState.value, platform, updateReady: updateReady.value }),
    );

    /** A route's file could not be loaded: most likely the portal was updated meanwhile. */
    function markStale() {
        stale.value = true;
    }

    function applyUpdate() {
        applyWorkerUpdate();
    }

    function promptInstall() {
        return openInstallDialog();
    }

    return {
        platform,
        standalone,
        updateReady,
        canInstall,
        installed,
        installState,
        showInstall,
        markStale,
        applyUpdate,
        promptInstall,
    };
});
