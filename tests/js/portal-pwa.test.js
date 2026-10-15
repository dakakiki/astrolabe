import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';

import {
    base64UrlToUint8Array,
    contentEncoding,
    deviceLabel,
    installView,
    isChunkLoadError,
    madeWithOtherKey,
    platformOf,
    pushDevices,
    pushProblem,
    pushSupport,
    sameKey,
    sha256Hex,
    showInstallCard,
    supportNotice,
    UPDATE_CHECK_INTERVAL,
    updateCheckDue,
} from '../../resources/js/portal/lib/pwa';
import en from '../../resources/js/portal/locales/en.json';

const UA = {
    iPhoneSafari:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    iPhoneChrome:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1',
    iPod: 'Mozilla/5.0 (iPod touch; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
    iPadOld:
        'Mozilla/5.0 (iPad; CPU OS 15_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
    // iPadOS Safari asks for the desktop site: the same words as a Mac.
    macSafari:
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    macFirefox: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:131.0) Gecko/20100101 Firefox/131.0',
    androidChrome:
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
    samsung:
        'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
    androidFirefox: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0',
    windowsChrome:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    windowsEdge:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
    windowsFirefox: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
    linuxChrome:
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
};

const on = (browser, system) => `${browser} on ${system}`;

describe('which device this is', () => {
    it('knows iPhone, iPod and the older iPad by their own names', () => {
        for (const userAgent of [UA.iPhoneSafari, UA.iPhoneChrome, UA.iPod, UA.iPadOld]) {
            expect(platformOf({ userAgent, maxTouchPoints: 5 })).toBe('ios');
        }
    });

    it('tells an iPad asking for the desktop site from a Mac by its touch screen', () => {
        expect(platformOf({ userAgent: UA.macSafari, maxTouchPoints: 5 })).toBe('ios');
        expect(platformOf({ userAgent: UA.macSafari, maxTouchPoints: 0 })).toBe('desktop');
        expect(platformOf({ userAgent: UA.macSafari, maxTouchPoints: 1 })).toBe('desktop');
        // Only Apple's mobile Safari has navigator.standalone; true means the Home Screen app.
        expect(platformOf({ userAgent: UA.macSafari, maxTouchPoints: 0, standalone: true })).toBe('ios');
        expect(platformOf({ userAgent: UA.macSafari, maxTouchPoints: 0, standalone: false })).toBe('desktop');
    });

    it('knows Android whatever the browser', () => {
        for (const userAgent of [UA.androidChrome, UA.samsung, UA.androidFirefox]) {
            expect(platformOf({ userAgent, maxTouchPoints: 5 })).toBe('android');
        }
    });

    it('takes everything else for a desktop', () => {
        for (const userAgent of [UA.windowsChrome, UA.windowsEdge, UA.windowsFirefox, UA.macFirefox, UA.linuxChrome]) {
            expect(platformOf({ userAgent, maxTouchPoints: 0 })).toBe('desktop');
        }

        expect(platformOf({ userAgent: '' })).toBe('desktop');
        expect(platformOf({ userAgent: null })).toBe('desktop');
        expect(platformOf()).toBe('desktop');
    });
});

describe('a short name for a device', () => {
    it('names the browser and the system', () => {
        expect(deviceLabel(UA.iPhoneSafari, on)).toBe('Safari on iOS');
        expect(deviceLabel(UA.iPhoneChrome, on)).toBe('Chrome on iOS');
        expect(deviceLabel(UA.androidChrome, on)).toBe('Chrome on Android');
        expect(deviceLabel(UA.androidFirefox, on)).toBe('Firefox on Android');
        expect(deviceLabel(UA.windowsChrome, on)).toBe('Chrome on Windows');
        expect(deviceLabel(UA.windowsEdge, on)).toBe('Edge on Windows');
        expect(deviceLabel(UA.windowsFirefox, on)).toBe('Firefox on Windows');
        expect(deviceLabel(UA.macSafari, on)).toBe('Safari on macOS');
        expect(deviceLabel(UA.linuxChrome, on)).toBe('Chrome on Linux');
    });

    it("calls Samsung's browser by its own name, though it also says Chrome", () => {
        expect(deviceLabel(UA.samsung, on)).toBe('Samsung Internet on Android');
    });

    it('gives the one part it knows, or nothing', () => {
        expect(deviceLabel('SomeAgent/1.0 (Windows NT 10.0)', on)).toBe('Windows');
        expect(deviceLabel('Firefox/131.0', on)).toBe('Firefox');
        expect(deviceLabel('curl/8.4.0', on)).toBeNull();
        expect(deviceLabel('', on)).toBeNull();
        expect(deviceLabel(null, on)).toBeNull();
    });
});

describe('whether push can work here', () => {
    const ready = {
        secure: true,
        serviceWorker: true,
        pushManager: true,
        notification: true,
        permission: 'default',
        platform: 'android',
        standalone: false,
    };

    it('is ok in a capable browser, asked or already allowed', () => {
        expect(pushSupport(ready)).toBe('ok');
        expect(pushSupport({ ...ready, permission: 'granted' })).toBe('ok');
        expect(pushSupport({ ...ready, platform: 'desktop' })).toBe('ok');
    });

    it('needs a secure connection first', () => {
        expect(pushSupport({ ...ready, secure: false })).toBe('insecure');
        expect(pushSupport({ ...ready, secure: false, platform: 'ios' })).toBe('insecure');
        expect(pushSupport()).toBe('insecure');
    });

    it('sends iPhone and iPad to the installed app first', () => {
        expect(pushSupport({ ...ready, platform: 'ios', pushManager: false })).toBe('install-first');
        expect(pushSupport({ ...ready, platform: 'ios', standalone: false })).toBe('install-first');
        // Installed on iOS 16.4 or later: like any other browser.
        expect(pushSupport({ ...ready, platform: 'ios', standalone: true })).toBe('ok');
        // Installed on an older iOS: no Push API.
        expect(pushSupport({ ...ready, platform: 'ios', standalone: true, pushManager: false })).toBe('unsupported');
    });

    it('is unsupported without a service worker, the Push API or notifications', () => {
        expect(pushSupport({ ...ready, serviceWorker: false })).toBe('unsupported');
        expect(pushSupport({ ...ready, pushManager: false })).toBe('unsupported');
        expect(pushSupport({ ...ready, notification: false })).toBe('unsupported');
    });

    it('says when the person blocked notifications for the site', () => {
        expect(pushSupport({ ...ready, permission: 'denied' })).toBe('denied');
        expect(pushSupport({ ...ready, platform: 'ios', standalone: true, permission: 'denied' })).toBe('denied');
    });

    it('has a text for every answer but ok', () => {
        for (const support of ['insecure', 'install-first', 'unsupported', 'denied']) {
            const key = supportNotice(support);

            expect(key).not.toBeNull();
            expect(en.portal.notifications.states[key]).toBeTruthy();
        }

        expect(supportNotice('ok')).toBeNull();
        expect(supportNotice(null)).toBeNull();
    });
});

describe('the push key', () => {
    it('reads base64url, with or without padding', () => {
        expect([...base64UrlToUint8Array('AQID')]).toEqual([1, 2, 3]);
        expect([...base64UrlToUint8Array('AQI')]).toEqual([1, 2]);
        expect([...base64UrlToUint8Array('AQI=')]).toEqual([1, 2]);
        expect([...base64UrlToUint8Array('AQ')]).toEqual([1]);
        expect([...base64UrlToUint8Array('AQ==')]).toEqual([1]);
        expect([...base64UrlToUint8Array(' AQID\n')]).toEqual([1, 2, 3]);
        expect([...base64UrlToUint8Array('')]).toEqual([]);
    });

    it('reads the URL-safe letters as + and /', () => {
        expect([...base64UrlToUint8Array('-_-_')]).toEqual([251, 255, 191]);
        expect([...base64UrlToUint8Array('+/+/')]).toEqual([251, 255, 191]);
    });

    it('gives back the 65 bytes of a VAPID public key', () => {
        const bytes = Uint8Array.from({ length: 65 }, (_, index) => (index === 0 ? 4 : (index * 37) % 256));
        const key = Buffer.from(bytes).toString('base64url');

        expect(key).toHaveLength(87);
        expect(base64UrlToUint8Array(key)).toEqual(bytes);
    });

    it("compares a subscription's key with the portal's", () => {
        const key = Uint8Array.from([4, 1, 2, 3]);

        expect(sameKey(key.buffer, key)).toBe(true);
        expect(sameKey(Uint8Array.from([4, 1, 2, 3]), key)).toBe(true);
        expect(sameKey(Uint8Array.from([4, 1, 2, 9]), key)).toBe(false);
        expect(sameKey(Uint8Array.from([4, 1, 2]), key)).toBe(false);
        expect(sameKey(null, key)).toBe(false);
        expect(sameKey(key, null)).toBe(false);
    });

    it("tells a subscription made with the portal's earlier keys", () => {
        const key = Uint8Array.from([4, 1, 2, 3]);
        const publicKey = Buffer.from(key).toString('base64url');

        // The browser gives the key as an ArrayBuffer.
        expect(madeWithOtherKey(key.buffer, publicKey)).toBe(false);
        expect(madeWithOtherKey(key.buffer, `${publicKey}==`)).toBe(false);
        expect(madeWithOtherKey(Uint8Array.from([4, 1, 2, 9]).buffer, publicKey)).toBe(true);
        expect(madeWithOtherKey(Uint8Array.from([4, 1, 2]).buffer, publicKey)).toBe(true);
    });

    it('leaves a subscription alone when there is nothing to compare', () => {
        const key = Uint8Array.from([4, 1, 2, 3]);

        // An older browser does not say which key it used.
        expect(madeWithOtherKey(undefined, 'BAECAw')).toBe(false);
        expect(madeWithOtherKey(null, 'BAECAw')).toBe(false);
        // The portal has no key (push is off there).
        expect(madeWithOtherKey(key.buffer, null)).toBe(false);
        expect(madeWithOtherKey(key.buffer, '')).toBe(false);
    });

    it('asks for the standard encoding when the browser lists it', () => {
        expect(contentEncoding(['aes128gcm', 'aesgcm'])).toBe('aes128gcm');
        expect(contentEncoding(['aesgcm'])).toBe('aesgcm');
        expect(contentEncoding([])).toBe('aesgcm');
        expect(contentEncoding(undefined)).toBe('aesgcm');
    });
});

describe('this device among the account’s devices', () => {
    it('hashes an endpoint as the server does', async () => {
        const endpoint = 'https://fcm.googleapis.com/fcm/send/abc:DEF-123_xyz';

        expect(await sha256Hex('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
        expect(await sha256Hex('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
        expect(await sha256Hex(endpoint)).toBe(createHash('sha256').update(endpoint).digest('hex'));
    });

    it('finds this browser by its hash and counts the others', () => {
        const devices = [
            { hash: 'mine', current: true, user_agent: UA.androidChrome },
            { hash: 'phone', current: false, user_agent: UA.iPhoneSafari },
            { hash: 'laptop', current: false, user_agent: UA.windowsEdge },
        ];
        const state = pushDevices(devices, 'mine');

        expect(state.here).toBe(true);
        expect(state.others.map((device) => device.hash)).toEqual(['phone', 'laptop']);
        expect(state.any).toBe(true);
    });

    it('is off here when this browser has no subscription, while others still count', () => {
        const state = pushDevices([{ hash: 'phone', current: false }], null);

        expect(state.here).toBe(false);
        expect(state.others).toHaveLength(1);
        expect(state.any).toBe(true);
    });

    it('does not count a stale row of this session as another device', () => {
        const state = pushDevices([{ hash: 'old-endpoint', current: true }], null);

        expect(state).toEqual({ here: false, others: [], any: false });
    });

    it('copes with no devices at all', () => {
        expect(pushDevices([], 'mine')).toEqual({ here: false, others: [], any: false });
        expect(pushDevices(undefined, null)).toEqual({ here: false, others: [], any: false });
    });
});

describe('what went wrong with push', () => {
    const answer = (status, data = {}) => ({ response: { status, data } });

    it('stays quiet when the portal already moved the person on', () => {
        expect(pushProblem(answer(401))).toBeNull();
        expect(pushProblem(answer(403, { code: 'portal_no_access' }))).toBeNull();
    });

    it('names each answer of the push API', () => {
        expect(pushProblem(answer(409, { code: 'push_unavailable' }))).toBe('unavailable');
        expect(pushProblem(answer(404, { code: 'push_not_on' }))).toBe('notOn');
        expect(pushProblem(answer(422, { errors: { endpoint: ['…'] } }))).toBe('service');
        expect(pushProblem(answer(429))).toBe('tooMany');
        expect(pushProblem(answer(500))).toBe('failed');
    });

    it("takes the browser's own refusals and a lost connection for a failure", () => {
        expect(pushProblem({ name: 'NotAllowedError', message: 'Registration failed - permission denied' })).toBe(
            'failed',
        );
        expect(pushProblem(new Error('Network Error'))).toBe('failed');
        expect(pushProblem(undefined)).toBe('failed');
    });

    it('has a text for every problem', () => {
        for (const problem of ['unavailable', 'notOn', 'service', 'tooMany', 'failed']) {
            expect(en.portal.notifications.problems[problem]).toBeTruthy();
        }
    });
});

describe('installing the portal', () => {
    it('says so inside the installed app above all', () => {
        expect(installView({ standalone: true, installed: true, canInstall: true, platform: 'ios' })).toBe(
            'standalone',
        );
    });

    it('says it is installed right after installing from this tab', () => {
        expect(installView({ installed: true, canInstall: false, platform: 'android' })).toBe('installed');
    });

    it("offers the browser's own dialog when there is one", () => {
        expect(installView({ canInstall: true, platform: 'android' })).toBe('prompt');
        expect(installView({ canInstall: true, platform: 'desktop' })).toBe('prompt');
    });

    it('shows the Share steps on iPhone and iPad', () => {
        expect(installView({ platform: 'ios' })).toBe('ios');
    });

    it('falls back to a hint elsewhere', () => {
        expect(installView({ platform: 'desktop' })).toBe('hint');
        expect(installView({ platform: 'android' })).toBe('hint');
        expect(installView()).toBe('hint');
    });

    it('offers it on Home only while it can be done and until "Not now"', () => {
        expect(showInstallCard({ view: 'prompt' })).toBe(true);
        expect(showInstallCard({ view: 'ios' })).toBe(true);
        expect(showInstallCard({ view: 'prompt', dismissed: true })).toBe(false);
        expect(showInstallCard({ view: 'ios', dismissed: true })).toBe(false);

        for (const view of ['standalone', 'installed', 'hint']) {
            expect(showInstallCard({ view })).toBe(false);
        }
    });
});

describe('a new version', () => {
    it('asks for a new service worker at most once an hour', () => {
        const now = Date.parse('2026-10-15T12:00:00Z');

        expect(updateCheckDue(0, now)).toBe(true);
        expect(updateCheckDue(now - 5 * 60_000, now)).toBe(false);
        expect(updateCheckDue(now - UPDATE_CHECK_INTERVAL + 1, now)).toBe(false);
        expect(updateCheckDue(now - UPDATE_CHECK_INTERVAL, now)).toBe(true);
        expect(updateCheckDue(now - 1000, now, 500)).toBe(true);
    });

    it("recognises a screen's file that failed to load, in every browser", () => {
        const messages = [
            // Chrome and Edge
            'Failed to fetch dynamically imported module: https://portal.astrolabe.online/build/assets/ProfilePage-Bx1.js',
            // Firefox
            'error loading dynamically imported module: https://portal.astrolabe.online/build/assets/ProfilePage-Bx1.js',
            // Safari
            'Importing a module script failed.',
            "'text/html' is not a valid JavaScript MIME type.",
            // Vite's stylesheet preload
            'Unable to preload CSS for /build/assets/BookPage-C2a.css',
            'Loading chunk 42 failed.',
            'Loading CSS chunk book-page failed.',
        ];

        for (const message of messages) {
            expect(isChunkLoadError(new TypeError(message))).toBe(true);
            expect(isChunkLoadError(message)).toBe(true);
        }

        expect(isChunkLoadError(Object.assign(new Error('x'), { name: 'ChunkLoadError' }))).toBe(true);
    });

    it('leaves other errors alone', () => {
        expect(isChunkLoadError(new TypeError("Cannot read properties of undefined (reading 'name')"))).toBe(false);
        expect(isChunkLoadError(new Error('Request failed with status code 500'))).toBe(false);
        expect(isChunkLoadError({ type: 4, from: {}, to: {} })).toBe(false);
        expect(isChunkLoadError(null)).toBe(false);
        expect(isChunkLoadError(undefined)).toBe(false);
    });
});

describe('the texts', () => {
    it('has every message the PWA screens use', () => {
        const { notifications, pwa, profile } = en.portal;

        for (const key of [
            'isOn',
            'isOff',
            'turnOn',
            'turnOff',
            'test',
            'testSent',
            'testFailed',
            'byEmail',
            'saved',
        ]) {
            expect(notifications[key]).toBeTruthy();
        }

        expect(Object.keys(notifications.channels)).toEqual(['email', 'push', 'both']);
        expect(notifications.others.split(' | ')).toHaveLength(2);
        expect(pwa.update).toEqual({ available: expect.any(String), reload: expect.any(String) });
        expect(Object.keys(pwa.install)).toEqual(
            expect.arrayContaining(['action', 'notNow', 'standalone', 'installed', 'iosSteps', 'iosNote', 'hint']),
        );
        // The reminder is no longer email only (Phase 9c).
        expect(profile.reminders).not.toMatch(/email/i);
        expect(profile.remindersHint).not.toMatch(/\{email\}/);
    });
});
