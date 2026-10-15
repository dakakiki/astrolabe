import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { installView, platformOf, showInstallCard } from '@/portal/lib/pwa';
import { appInstalled, canPrompt, promptInstall as openInstallDialog } from '@/portal/pwa/install';
import { applyUpdate as applyWorkerUpdate, updateWaiting } from '@/portal/pwa/serviceWorker';

// "Not now" on Home's install card: a UI flag of this browser, never client data.
const HINT_KEY = 'astrolabe.portal.install-hint';

function readDismissed() {
    try {
        return localStorage.getItem(HINT_KEY) === 'dismissed';
    } catch {
        return false;
    }
}

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
    const installHintDismissed = ref(readDismissed());
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
    const showHomeInstall = computed(() =>
        showInstallCard({ view: installState.value, dismissed: installHintDismissed.value }),
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

    function dismissInstallHint() {
        installHintDismissed.value = true;

        try {
            localStorage.setItem(HINT_KEY, 'dismissed');
        } catch {
            // Storage can be unavailable (private mode); the card stays hidden for this visit.
        }
    }

    return {
        platform,
        standalone,
        updateReady,
        canInstall,
        installed,
        installState,
        installHintDismissed,
        showHomeInstall,
        markStale,
        applyUpdate,
        promptInstall,
        dismissInstallHint,
    };
});
