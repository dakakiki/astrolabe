import { ref } from 'vue';

import { updateCheckDue } from '@/portal/lib/pwa';

/**
 * The portal's service worker from the page's side (Phase 9c, docs/spec/12,
 * "Service worker"): registers /sw.js in a production build, notices when a new
 * version waits, and lets it take over only when the person presses "Reload".
 * Thin glue over the browser's API; the decisions are in lib/pwa.js.
 */

/** A new version is installed and waits for "Reload". */
export const updateWaiting = ref(false);

let registration = null;
let registering = null;
let lastCheck = 0;
let reloadArmed = false;
let reloading = false;

/**
 * Registers the worker after the page has loaded (it should not compete with the
 * portal's own files). Only a built portal on a secure origin has one: the dev
 * server's files change all the time and must never be served from a cache.
 */
export function registerServiceWorker() {
    if (registering || !import.meta.env.PROD || !('serviceWorker' in navigator) || !window.isSecureContext) return;

    registering = new Promise((resolve) => {
        const start = () =>
            navigator.serviceWorker
                .register('/sw.js', { scope: '/', updateViaCache: 'none' })
                .then((result) => {
                    watchRegistration(result);
                    resolve(result);
                })
                .catch(() => resolve(null));

        if (document.readyState === 'complete') {
            start();
        } else {
            window.addEventListener('load', start, { once: true });
        }
    });

    // A portal left open for days still hears about a new version when the person comes back to it.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkForUpdate();
    });
}

/** The worker's registration (null without one) — what web push subscribes through. */
export function workerRegistration() {
    if (registering) return registering;
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return Promise.resolve(null);

    return navigator.serviceWorker.getRegistration('/').then(
        (result) => result ?? null,
        () => null,
    );
}

function watchRegistration(result) {
    registration = result;
    lastCheck = Date.now();

    if (result.waiting) updateWaiting.value = true;

    result.addEventListener('updatefound', () => {
        const worker = result.installing;

        worker?.addEventListener('statechange', () => {
            // Without a controller this is the first install, not an update.
            if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                updateWaiting.value = true;
            }
        });
    });
}

function checkForUpdate() {
    const now = Date.now();

    if (!registration || !updateCheckDue(lastCheck, now)) return;

    lastCheck = now;
    registration.update().catch(() => {
        // Offline or the server is busy: the next visit asks again.
    });
}

/**
 * "Reload": the waiting version takes over and the page reloads once it controls
 * the page. Without a waiting version (a route's file failed to load) it just
 * reloads. The page reloads only after the person asked, and only once.
 */
export function applyUpdate() {
    const waiting = registration?.waiting;

    if (!waiting) {
        reload();
        return;
    }

    if (!reloadArmed) {
        reloadArmed = true;
        navigator.serviceWorker.addEventListener('controllerchange', reload);
        // Should the switch not be announced, reload anyway rather than leave the button dead.
        setTimeout(reload, 4000);
    }

    waiting.postMessage({ type: 'SKIP_WAITING' });
}

function reload() {
    if (reloading) return;

    reloading = true;
    window.location.reload();
}
