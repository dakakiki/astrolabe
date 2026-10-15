import { ref } from 'vue';

/**
 * Installing the portal as an app (Phase 9c, docs/spec/12, "Instalacija").
 * Chrome and Edge announce that the portal can be installed with
 * `beforeinstallprompt`; the event is kept (and the browser's own mini bar
 * held back) so "Install app" in Profile and on Home can open the browser's
 * dialog later. Safari has no such event — iPhone and iPad get the
 * "Share → Add to Home Screen" steps instead.
 */

/** The browser's install dialog can be opened. */
export const canPrompt = ref(false);
/** The portal was installed from this page. */
export const appInstalled = ref(false);

let deferred = null;
let capturing = false;

/** Listen as early as possible: the event may come right after the page loads. */
export function captureInstallPrompt() {
    if (capturing || typeof window === 'undefined') return;

    capturing = true;

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        canPrompt.value = true;
    });

    window.addEventListener('appinstalled', () => {
        deferred = null;
        canPrompt.value = false;
        appInstalled.value = true;
    });
}

/** Opens the browser's install dialog; an event works once. Resolves 'accepted', 'dismissed' or 'unavailable'. */
export async function promptInstall() {
    const event = deferred;

    if (!event) return 'unavailable';

    deferred = null;
    canPrompt.value = false;

    try {
        await event.prompt();
        const choice = await event.userChoice;

        return choice?.outcome === 'accepted' ? 'accepted' : 'dismissed';
    } catch {
        return 'unavailable';
    }
}
