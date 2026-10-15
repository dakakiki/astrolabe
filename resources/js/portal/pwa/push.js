import http from '@/portal/http';
import { base64UrlToUint8Array, contentEncoding, pushSupport, sameKey } from '@/portal/lib/pwa';
import { workerRegistration } from '@/portal/pwa/serviceWorker';

/**
 * Web push for this browser (Phase 9c, docs/spec/12, "Web push"): its current
 * subscription, switching it on and off with the portal's API, and dropping it
 * on sign-out. Thin glue over the browser's Push API; the decisions are in
 * lib/pwa.js. The subscription's endpoint is sent only to the portal's own API.
 */

/** How long "Turn on" waits for a worker that is still installing, at most. */
const READY_TIMEOUT = 10_000;

/** Whether push can be switched on here ('ok', 'insecure', 'install-first', 'unsupported', 'denied'). */
export async function pushSupportHere({ platform, standalone }) {
    const registration = window.isSecureContext ? await workerRegistration() : null;

    return pushSupport({
        secure: window.isSecureContext,
        serviceWorker: Boolean(registration),
        pushManager: 'PushManager' in window,
        notification: 'Notification' in window,
        permission: window.Notification?.permission,
        platform,
        standalone,
    });
}

/** This browser's push subscription, or null. */
export async function currentSubscription() {
    const registration = await workerRegistration();

    if (!registration?.pushManager) return null;

    return (await registration.pushManager.getSubscription()) ?? null;
}

/**
 * "Turn on for this device". Asks for permission first — call it straight from
 * the click, browsers allow the question only right after one. Resolves
 * `{ permission, endpoint, state }` (`state` is `GET /push`'s data, null when
 * permission was not given). Should the portal refuse the subscription, the
 * browser's is dropped again, so the two never disagree.
 */
export async function subscribe(publicKey) {
    const permission = await askPermission();

    if (permission !== 'granted') return { permission, endpoint: null, state: null };

    const registration = await readyRegistration();
    const key = base64UrlToUint8Array(publicKey);
    let subscription = await registration.pushManager.getSubscription();

    // Made with an earlier key: the browser refuses a new one until it is gone.
    if (subscription && !sameKey(subscription.options?.applicationServerKey, key)) {
        await subscription.unsubscribe();
        subscription = null;
    }

    subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });

    const { endpoint, keys } = subscription.toJSON();

    try {
        const response = await http.post('/push/subscriptions', {
            endpoint,
            keys,
            content_encoding: contentEncoding(window.PushManager?.supportedContentEncodings),
        });

        return { permission, endpoint, state: response.data.data };
    } catch (error) {
        await subscription.unsubscribe().catch(() => false);
        throw error;
    }
}

/**
 * "Turn off for this device": the portal forgets it first, then the browser.
 * Resolves `GET /push`'s data, or null when this browser had no subscription.
 */
export async function unsubscribe() {
    const subscription = await currentSubscription();

    if (!subscription) return null;

    const response = await http.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } });
    await subscription.unsubscribe().catch(() => false);

    return response.data.data;
}

/**
 * Drops this browser's subscription without asking the portal (on sign-out the
 * server forgets the session's devices itself). Never rejects, and gives up
 * after `timeout` ms so signing out is never held up for longer than a moment.
 */
export function unsubscribeLocally(timeout = 1500) {
    const work = currentSubscription()
        .then((subscription) => subscription?.unsubscribe())
        .catch(() => false);

    return Promise.race([work, new Promise((resolve) => setTimeout(resolve, timeout))]).then(() => undefined);
}

/** "Send a test notification" to this browser; resolves whether the push service took it. */
export async function sendTest(endpoint) {
    const response = await http.post('/push/test', { endpoint });

    return Boolean(response.data.data?.sent);
}

/** Older Safari answers through a callback, newer browsers with a promise. */
function askPermission() {
    return new Promise((resolve) => {
        const answer = window.Notification.requestPermission(resolve);

        if (answer?.then) answer.then(resolve, () => resolve('default'));
    });
}

/**
 * Push needs an active worker; on the very first visit it may still be installing.
 * Never waits for ever: should the first install have failed (a file could not be
 * fetched on a flaky connection), nothing in this page becomes ready, and the
 * rejection lets "Turn on" say so and be pressed again.
 */
async function readyRegistration() {
    const registration = await workerRegistration();

    if (!registration) throw new Error('No service worker');
    if (registration.active) return registration;
    if (!registration.installing && !registration.waiting) throw new Error('No service worker');

    let timer;

    try {
        return await Promise.race([
            navigator.serviceWorker.ready,
            new Promise((resolve, reject) => {
                timer = setTimeout(() => reject(new Error('Service worker not ready')), READY_TIMEOUT);
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}
