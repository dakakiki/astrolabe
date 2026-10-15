/**
 * Pure helpers of the client portal as an installed app (Phase 9c, docs/spec/12,
 * "PWA za klijente"): which device this is, whether web push can work here, what
 * to offer for installing, and reading the browser's push and loading errors.
 * No browser globals are read here — the callers pass what they saw — so all of
 * it is covered by Vitest.
 */

import { describeDevice } from '@/lib/security';

/** Check for a new version of the service worker at most this often. */
export const UPDATE_CHECK_INTERVAL = 60 * 60 * 1000;

/**
 * 'ios' (iPhone, iPod, iPad), 'android' or 'desktop'. iPadOS Safari asks for the
 * desktop site and says "Macintosh"; only its touch screen (or `navigator.standalone`,
 * which only Apple's mobile Safari has) tells it apart from a Mac.
 */
export function platformOf({ userAgent = '', maxTouchPoints = 0, standalone } = {}) {
    const agent = String(userAgent ?? '');

    if (/iPhone|iPad|iPod/.test(agent)) return 'ios';
    if (/Macintosh/.test(agent) && (Number(maxTouchPoints) > 1 || standalone === true)) return 'ios';
    if (/Android/.test(agent)) return 'android';

    return 'desktop';
}

/**
 * Whether web push can be switched on in this browser:
 * 'ok', 'insecure' (not https), 'install-first' (iPhone or iPad outside the
 * installed app — iOS gives push only to a Home Screen app, 16.4+),
 * 'unsupported' (no service worker, Push API or notifications) or 'denied'
 * (the person blocked notifications for the site).
 */
export function pushSupport({
    secure = false,
    serviceWorker = false,
    pushManager = false,
    notification = false,
    permission = 'default',
    platform = 'desktop',
    standalone = false,
} = {}) {
    if (!secure) return 'insecure';
    if (platform === 'ios' && !standalone) return 'install-first';
    if (!serviceWorker || !pushManager || !notification) return 'unsupported';
    if (permission === 'denied') return 'denied';

    return 'ok';
}

const SUPPORT_NOTICES = {
    insecure: 'insecure',
    'install-first': 'installFirst',
    unsupported: 'unsupported',
    denied: 'denied',
};

/** The key of the text (`portal.notifications.states.*`) saying why push cannot be switched on here, or null. */
export function supportNotice(support) {
    return SUPPORT_NOTICES[support] ?? null;
}

/** The VAPID public key (base64url, with or without padding) as the bytes `pushManager.subscribe()` takes. */
export function base64UrlToUint8Array(value) {
    const text = String(value ?? '')
        .trim()
        .replace(/=+$/, '');
    const base64 = (text + '='.repeat((4 - (text.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

/**
 * Whether an existing subscription was made with this key. After the keys
 * change, the browser refuses a new subscription until the old one is gone.
 */
export function sameKey(current, wanted) {
    if (!current || !wanted) return false;

    const a = current instanceof Uint8Array ? current : new Uint8Array(current);
    const b = wanted instanceof Uint8Array ? wanted : new Uint8Array(wanted);

    return a.length === b.length && a.every((byte, index) => byte === b[index]);
}

/**
 * Whether a subscription was made with VAPID keys the portal no longer signs with
 * (`applicationServerKey` from its options, the portal's `public_key`): the push
 * service would refuse every notice, yet the device would show "On". False when
 * either is missing — an older browser does not say which key it used.
 */
export function madeWithOtherKey(applicationServerKey, publicKey) {
    if (!applicationServerKey || !publicKey) return false;

    return !sameKey(applicationServerKey, base64UrlToUint8Array(publicKey));
}

/** The payload encoding to ask the server for: the standard one when the browser lists it, else the older draft. */
export function contentEncoding(supported) {
    return Array.isArray(supported) && supported.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
}

/** SHA-256 of a text as lowercase hex — how the server names a device without sending its endpoint back. */
export async function sha256Hex(text) {
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(String(text)));

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * The account's devices with push on (`GET /push` → devices), seen from this
 * browser: whether this one is among them (its endpoint's hash), and the
 * others. A row of this session that is not this browser's current
 * subscription is a stale copy of this device, not another device.
 */
export function pushDevices(devices, hash) {
    const list = Array.isArray(devices) ? devices : [];
    const here = Boolean(hash) && list.some((device) => device.hash === hash);
    const others = list.filter((device) => device.hash !== hash && !device.current);

    return { here, others, any: here || others.length > 0 };
}

/** The message key for a failed push request, or null when the portal already moved on (401, 403). */
export function pushProblem(error) {
    const status = error?.response?.status;
    const code = error?.response?.data?.code;

    if (status === 401 || status === 403) return null;
    if (code === 'push_unavailable') return 'unavailable';
    if (code === 'push_not_on') return 'notOn';
    if (status === 422) return 'service';
    if (status === 429) return 'tooMany';

    return 'failed';
}

/**
 * What the "App" card offers: 'standalone' (running as the installed app),
 * 'installed' (installed from this tab just now), 'prompt' (the browser's own
 * install dialog is ready), 'ios' (Share → Add to Home Screen) or 'hint'.
 */
export function installView({ standalone = false, installed = false, canInstall = false, platform = 'desktop' } = {}) {
    if (standalone) return 'standalone';
    if (installed) return 'installed';
    if (canInstall) return 'prompt';
    if (platform === 'ios') return 'ios';

    return 'hint';
}

/** Home offers installing once, discreetly: only while it can be done and until the person says "Not now". */
export function showInstallCard({ view, dismissed = false } = {}) {
    return !dismissed && (view === 'prompt' || view === 'ios');
}

/** Whether to ask the server for a new service worker again (on returning to the page). */
export function updateCheckDue(lastCheck, now, interval = UPDATE_CHECK_INTERVAL) {
    return !lastCheck || now - lastCheck >= interval;
}

// How browsers word a lazily loaded file that is gone (a new version replaced it) or unreachable.
const CHUNK_ERRORS = [
    /Failed to fetch dynamically imported module/i, // Chrome, Edge
    /error loading dynamically imported module/i, // Firefox
    /Importing a module script failed/i, // Safari
    /is not a valid JavaScript MIME type/i, // Safari, when a page came back instead of the file
    /Unable to preload CSS/i, // Vite's stylesheet preload
    /Loading (CSS )?chunk [\w-]+ failed/i,
];

/** Whether a route's file failed to load — the portal was updated, or the connection dropped. */
export function isChunkLoadError(error) {
    if (!error) return false;
    if (error.name === 'ChunkLoadError') return true;

    const message = typeof error === 'string' ? error : String(error.message ?? '');

    return CHUNK_ERRORS.some((pattern) => pattern.test(message));
}

/**
 * A short name for a device from its User-Agent ("Chrome on Android"), worded
 * by `join(browser, system)` (the caller's translation); one part alone when
 * the other is not recognised, null when neither is.
 */
export function deviceLabel(userAgent, join) {
    if (!userAgent) return null;

    const { browser, system } = describeDevice(userAgent);
    // Samsung's browser also says "Chrome"; its own name is what people know.
    const name = /SamsungBrowser\//.test(userAgent) ? 'Samsung Internet' : browser;

    if (name && system) return join(name, system);

    return name ?? system ?? null;
}
