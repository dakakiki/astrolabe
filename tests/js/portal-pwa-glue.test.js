import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
// Vue looks at `document` when it is first loaded: load it before a test stubs one.
import 'vue';

import { base64UrlToUint8Array, pushProblem } from '../../resources/js/portal/lib/pwa';

/*
 * The client PWA's browser glue (resources/js/portal/pwa): registering the service
 * worker, taking a new version, installing and web push. Vitest runs in Node, so
 * the page's globals are stubbed per test; the modules keep state (the
 * registration, whether "Reload" was pressed), so each test imports them afresh.
 */

const http = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

vi.mock('@/portal/http', () => ({ default: http }));

const loadWorker = () => import('../../resources/js/portal/pwa/serviceWorker.js');
const loadPush = () => import('../../resources/js/portal/pwa/push.js');
const loadInstall = () => import('../../resources/js/portal/pwa/install.js');

// The portal's VAPID public key (base64url) and its bytes, as the browser keeps them.
const KEY = 'BAECAw';
const KEY_BYTES = Uint8Array.from([4, 1, 2, 3]);
const OLD = 'https://fcm.googleapis.com/fcm/send/old';
const NEW = 'https://fcm.googleapis.com/fcm/send/new';
const KEYS = { p256dh: 'BPublicKeyOfTheBrowser', auth: 'AuthSecret' };
const STATE = { available: true, public_key: KEY, channel: 'push', devices: [] };

/** A service worker in the given state. */
function fakeWorker(state = 'installed') {
    return Object.assign(new EventTarget(), { state, postMessage: vi.fn() });
}

/** A push subscription as the browser gives it, made with `key` (null: an older browser that does not say). */
function fakeSubscription(endpoint, key = KEY_BYTES) {
    return {
        endpoint,
        options: key ? { applicationServerKey: key.buffer } : {},
        toJSON: () => ({ endpoint, expirationTime: null, keys: KEYS }),
        unsubscribe: vi.fn(async () => true),
    };
}

/** A service worker registration: its workers, this browser's subscription and the one `subscribe()` makes. */
function fakeRegistration({
    active = fakeWorker('activated'),
    installing = null,
    waiting = null,
    subscription = null,
    created = fakeSubscription(NEW),
} = {}) {
    return Object.assign(new EventTarget(), {
        active,
        installing,
        waiting,
        created,
        update: vi.fn(async () => undefined),
        pushManager: {
            getSubscription: vi.fn(async () => subscription),
            subscribe: vi.fn(async () => created),
        },
    });
}

/** The page's window, document and navigator, with a service worker container unless `serviceWorker` is false. */
function stubBrowser({
    secure = true,
    serviceWorker = true,
    readyState = 'complete',
    registration = fakeRegistration(),
    permission = 'granted',
    encodings = ['aes128gcm', 'aesgcm'],
} = {}) {
    const page = Object.assign(new EventTarget(), {
        isSecureContext: secure,
        location: { reload: vi.fn() },
        Notification: { permission: 'default', requestPermission: vi.fn(async () => permission) },
        PushManager: encodings ? { supportedContentEncodings: encodings } : {},
    });
    const doc = Object.assign(new EventTarget(), { readyState, visibilityState: 'visible' });
    const container = Object.assign(new EventTarget(), {
        controller: null,
        // Like the browser's: settles only once a worker is active, which may be never.
        ready: new Promise(() => {}),
        register: vi.fn(async () => registration),
        getRegistration: vi.fn(async () => registration),
    });

    vi.stubGlobal('window', page);
    vi.stubGlobal('document', doc);
    vi.stubGlobal('navigator', serviceWorker ? { serviceWorker: container } : {});

    return { window: page, document: doc, container, registration };
}

beforeEach(() => {
    vi.resetModules();
    vi.resetAllMocks();
    http.post.mockResolvedValue({ data: { data: STATE } });
    http.delete.mockResolvedValue({ data: { data: STATE } });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
});

describe('registering the service worker', () => {
    it('registers /sw.js for the whole portal once the page has loaded', async () => {
        vi.stubEnv('PROD', true);
        const browser = stubBrowser({ readyState: 'loading' });
        const { registerServiceWorker, workerRegistration } = await loadWorker();

        registerServiceWorker();
        registerServiceWorker();

        expect(browser.container.register).not.toHaveBeenCalled();

        browser.window.dispatchEvent(new Event('load'));

        expect(await workerRegistration()).toBe(browser.registration);
        expect(browser.container.register).toHaveBeenCalledTimes(1);
        expect(browser.container.register).toHaveBeenCalledWith('/sw.js', { scope: '/', updateViaCache: 'none' });
    });

    it('registers only in a production build, with service workers, on a secure origin', async () => {
        for (const [prod, options] of [
            [false, {}],
            [true, { secure: false }],
            [true, { serviceWorker: false }],
        ]) {
            vi.resetModules();
            vi.stubEnv('PROD', prod);
            const browser = stubBrowser(options);
            const { registerServiceWorker } = await loadWorker();

            registerServiceWorker();

            expect(browser.container.register).not.toHaveBeenCalled();
        }
    });

    it('finds no registration where service workers are missing', async () => {
        stubBrowser({ serviceWorker: false });
        const { workerRegistration } = await loadWorker();

        expect(await workerRegistration()).toBeNull();
    });

    it('notices a new version already waiting when the page loads', async () => {
        vi.stubEnv('PROD', true);
        stubBrowser({ registration: fakeRegistration({ waiting: fakeWorker() }) });
        const { registerServiceWorker, updateWaiting, workerRegistration } = await loadWorker();

        expect(updateWaiting.value).toBe(false);

        registerServiceWorker();
        await workerRegistration();

        expect(updateWaiting.value).toBe(true);
    });

    it('notices a new version installed during the visit', async () => {
        vi.stubEnv('PROD', true);
        const browser = stubBrowser();
        const { registerServiceWorker, updateWaiting, workerRegistration } = await loadWorker();

        registerServiceWorker();
        await workerRegistration();

        const worker = fakeWorker('installing');
        browser.container.controller = browser.registration.active;
        browser.registration.installing = worker;
        browser.registration.dispatchEvent(new Event('updatefound'));
        worker.state = 'installed';
        worker.dispatchEvent(new Event('statechange'));

        expect(updateWaiting.value).toBe(true);
    });

    it('asks for a new version when the person comes back after an hour', async () => {
        vi.useFakeTimers();
        vi.stubEnv('PROD', true);
        const browser = stubBrowser();
        const { registerServiceWorker, workerRegistration } = await loadWorker();

        registerServiceWorker();
        await workerRegistration();

        browser.document.dispatchEvent(new Event('visibilitychange'));

        expect(browser.registration.update).not.toHaveBeenCalled();

        vi.advanceTimersByTime(60 * 60 * 1000);
        browser.document.dispatchEvent(new Event('visibilitychange'));

        expect(browser.registration.update).toHaveBeenCalledTimes(1);
    });
});

describe('taking a new version', () => {
    /** A registered worker with a new version waiting, as the page sees it. */
    async function withWaitingVersion() {
        vi.stubEnv('PROD', true);
        const waiting = fakeWorker();
        const browser = stubBrowser({ registration: fakeRegistration({ waiting }) });
        const worker = await loadWorker();

        worker.registerServiceWorker();
        await worker.workerRegistration();

        return { ...browser, waiting, worker };
    }

    it('lets the waiting version take over on "Reload" and reloads once it does', async () => {
        vi.useFakeTimers();
        const { window, container, waiting, worker } = await withWaitingVersion();

        worker.applyUpdate();
        worker.applyUpdate();

        expect(waiting.postMessage).toHaveBeenCalledWith({ type: 'SKIP_WAITING' });
        expect(window.location.reload).not.toHaveBeenCalled();

        container.dispatchEvent(new Event('controllerchange'));
        container.dispatchEvent(new Event('controllerchange'));
        vi.advanceTimersByTime(4000);

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('reloads anyway when the switch is never announced', async () => {
        vi.useFakeTimers();
        const { window, worker } = await withWaitingVersion();

        worker.applyUpdate();
        vi.advanceTimersByTime(3999);

        expect(window.location.reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('never reloads by itself when a worker takes over (the first install claims the page)', async () => {
        vi.useFakeTimers();
        vi.stubEnv('PROD', true);
        const installing = fakeWorker('installing');
        const browser = stubBrowser({ registration: fakeRegistration({ active: null, installing }) });
        const { registerServiceWorker, updateWaiting, workerRegistration } = await loadWorker();

        registerServiceWorker();
        await workerRegistration();

        browser.registration.dispatchEvent(new Event('updatefound'));
        installing.state = 'installed';
        installing.dispatchEvent(new Event('statechange'));
        // clients.claim() in the new worker's activate.
        browser.container.controller = installing;
        browser.container.dispatchEvent(new Event('controllerchange'));
        vi.advanceTimersByTime(10_000);

        expect(updateWaiting.value).toBe(false);
        expect(browser.window.location.reload).not.toHaveBeenCalled();
    });

    it('just reloads without a waiting version (a screen failed to load)', async () => {
        vi.stubEnv('PROD', true);
        const browser = stubBrowser();
        const { applyUpdate, registerServiceWorker, workerRegistration } = await loadWorker();

        registerServiceWorker();
        await workerRegistration();
        applyUpdate();

        expect(browser.registration.active.postMessage).not.toHaveBeenCalled();
        expect(browser.window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('just reloads without a service worker at all', async () => {
        const browser = stubBrowser();
        const { applyUpdate } = await loadWorker();

        applyUpdate();
        applyUpdate();

        expect(browser.window.location.reload).toHaveBeenCalledTimes(1);
    });
});

describe('turning push on for this device', () => {
    it('asks for permission first, subscribes with the portal key and hands the subscription to the portal', async () => {
        const browser = stubBrowser();
        const { subscribe } = await loadPush();

        const result = await subscribe(KEY);

        expect(browser.window.Notification.requestPermission).toHaveBeenCalledTimes(1);
        expect(browser.window.Notification.requestPermission.mock.invocationCallOrder[0]).toBeLessThan(
            browser.container.getRegistration.mock.invocationCallOrder[0],
        );
        expect(browser.registration.pushManager.subscribe).toHaveBeenCalledWith({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(KEY),
        });
        expect(http.post).toHaveBeenCalledTimes(1);
        expect(http.post).toHaveBeenCalledWith('/push/subscriptions', {
            endpoint: NEW,
            keys: KEYS,
            content_encoding: 'aes128gcm',
        });
        expect(result).toEqual({ permission: 'granted', endpoint: NEW, state: STATE });
    });

    it('drops a subscription made with an earlier key before subscribing again', async () => {
        const old = fakeSubscription(OLD, Uint8Array.from([4, 9, 9, 9]));
        const browser = stubBrowser({ registration: fakeRegistration({ subscription: old }) });
        const { subscribe } = await loadPush();

        const result = await subscribe(KEY);

        expect(old.unsubscribe).toHaveBeenCalledTimes(1);
        expect(old.unsubscribe.mock.invocationCallOrder[0]).toBeLessThan(
            browser.registration.pushManager.subscribe.mock.invocationCallOrder[0],
        );
        expect(http.post.mock.calls[0][1].endpoint).toBe(NEW);
        expect(result.endpoint).toBe(NEW);
    });

    it("keeps a subscription made with the portal's key", async () => {
        const current = fakeSubscription(OLD);
        const browser = stubBrowser({ registration: fakeRegistration({ subscription: current }) });
        const { subscribe } = await loadPush();

        const result = await subscribe(KEY);

        expect(current.unsubscribe).not.toHaveBeenCalled();
        expect(browser.registration.pushManager.subscribe).not.toHaveBeenCalled();
        expect(http.post.mock.calls[0][1].endpoint).toBe(OLD);
        expect(result.endpoint).toBe(OLD);
    });

    it('asks for the older encoding when the browser does not list the standard one', async () => {
        for (const encodings of [['aesgcm'], null]) {
            vi.resetModules();
            http.post.mockClear();
            stubBrowser({ encodings });
            const { subscribe } = await loadPush();

            await subscribe(KEY);

            expect(http.post.mock.calls[0][1].content_encoding).toBe('aesgcm');
        }
    });

    it('subscribes nothing when permission is not given', async () => {
        for (const permission of ['denied', 'default']) {
            vi.resetModules();
            const browser = stubBrowser({ permission });
            const { subscribe } = await loadPush();

            expect(await subscribe(KEY)).toEqual({ permission, endpoint: null, state: null });
            expect(browser.registration.pushManager.subscribe).not.toHaveBeenCalled();
        }

        expect(http.post).not.toHaveBeenCalled();
    });

    it('takes the answer from the callback in older Safari', async () => {
        const browser = stubBrowser();
        browser.window.Notification.requestPermission = vi.fn((callback) => callback('granted'));
        const { subscribe } = await loadPush();

        expect((await subscribe(KEY)).permission).toBe('granted');
        expect(http.post).toHaveBeenCalledTimes(1);
    });

    it("drops the browser's subscription again when the portal refuses it", async () => {
        const browser = stubBrowser();
        const refusal = Object.assign(new Error('Request failed'), { response: { status: 422, data: {} } });
        http.post.mockRejectedValue(refusal);
        const { subscribe } = await loadPush();

        await expect(subscribe(KEY)).rejects.toBe(refusal);
        expect(browser.registration.created.unsubscribe).toHaveBeenCalledTimes(1);
    });
});

describe('waiting for the service worker before subscribing', () => {
    it('gives up at once when no worker is left to become ready (the first install failed)', async () => {
        const registration = fakeRegistration({ active: null });
        stubBrowser({ registration });
        const { subscribe } = await loadPush();

        const error = await subscribe(KEY).catch((reason) => reason);

        expect(error).toBeInstanceOf(Error);
        // PushSettings says problems.failed and enables "Turn on" again.
        expect(pushProblem(error)).toBe('failed');
        expect(registration.pushManager.subscribe).not.toHaveBeenCalled();
        expect(http.post).not.toHaveBeenCalled();
    });

    it('gives up when there is no registration', async () => {
        stubBrowser({ serviceWorker: false });
        const { subscribe } = await loadPush();

        await expect(subscribe(KEY)).rejects.toThrow();
        expect(http.post).not.toHaveBeenCalled();
    });

    it('waits for a worker that is still installing, but not for ever', async () => {
        vi.useFakeTimers();
        const registration = fakeRegistration({ active: null, installing: fakeWorker('installing') });
        stubBrowser({ registration });
        const { subscribe } = await loadPush();

        let settled = false;
        const result = subscribe(KEY)
            .catch((reason) => reason)
            .finally(() => (settled = true));

        await vi.advanceTimersByTimeAsync(9_999);

        expect(settled).toBe(false);

        await vi.advanceTimersByTimeAsync(1);
        const error = await result;

        expect(error).toBeInstanceOf(Error);
        expect(pushProblem(error)).toBe('failed');
        expect(registration.pushManager.subscribe).not.toHaveBeenCalled();
        expect(http.post).not.toHaveBeenCalled();
    });

    it('subscribes once a waiting worker becomes ready, and leaves no timer behind', async () => {
        vi.useFakeTimers();
        const registration = fakeRegistration({ active: null, waiting: fakeWorker() });
        const browser = stubBrowser({ registration });
        let activate;
        browser.container.ready = new Promise((resolve) => (activate = resolve));
        const { subscribe } = await loadPush();

        const result = subscribe(KEY);
        await vi.advanceTimersByTimeAsync(2_000);
        registration.active = registration.waiting;
        activate(registration);

        await expect(result).resolves.toMatchObject({ permission: 'granted', endpoint: NEW });
        expect(vi.getTimerCount()).toBe(0);
    });
});

describe('turning push off for this device', () => {
    it('lets the portal forget this device first, then the browser', async () => {
        const subscription = fakeSubscription(OLD);
        stubBrowser({ registration: fakeRegistration({ subscription }) });
        const { unsubscribe } = await loadPush();

        expect(await unsubscribe()).toEqual(STATE);
        expect(http.delete).toHaveBeenCalledWith('/push/subscriptions', { data: { endpoint: OLD } });
        expect(subscription.unsubscribe).toHaveBeenCalledTimes(1);
        expect(http.delete.mock.invocationCallOrder[0]).toBeLessThan(
            subscription.unsubscribe.mock.invocationCallOrder[0],
        );
    });

    it('keeps the subscription when the portal could not forget it, so the two still agree', async () => {
        const subscription = fakeSubscription(OLD);
        stubBrowser({ registration: fakeRegistration({ subscription }) });
        http.delete.mockRejectedValue(new Error('Network Error'));
        const { unsubscribe } = await loadPush();

        await expect(unsubscribe()).rejects.toThrow('Network Error');
        expect(subscription.unsubscribe).not.toHaveBeenCalled();
    });

    it("is done once the portal forgot it, even when the browser's unsubscribe fails", async () => {
        const subscription = fakeSubscription(OLD);
        subscription.unsubscribe.mockRejectedValue(new Error('AbortError'));
        stubBrowser({ registration: fakeRegistration({ subscription }) });
        const { unsubscribe } = await loadPush();

        expect(await unsubscribe()).toEqual(STATE);
    });

    it('has nothing to do without a subscription', async () => {
        stubBrowser();
        const { unsubscribe } = await loadPush();

        expect(await unsubscribe()).toBeNull();
        expect(http.delete).not.toHaveBeenCalled();
    });

    it('drops the subscription on sign-out without asking the portal, and never holds sign-out up', async () => {
        vi.useFakeTimers();
        const subscription = fakeSubscription(OLD);
        subscription.unsubscribe.mockReturnValue(new Promise(() => {}));
        stubBrowser({ registration: fakeRegistration({ subscription }) });
        const { unsubscribeLocally } = await loadPush();

        let done = false;
        unsubscribeLocally().then(() => (done = true));
        await vi.advanceTimersByTimeAsync(1499);

        expect(subscription.unsubscribe).toHaveBeenCalledTimes(1);
        expect(done).toBe(false);

        await vi.advanceTimersByTimeAsync(1);

        expect(done).toBe(true);
        expect(http.delete).not.toHaveBeenCalled();
    });
});

describe('whether push can be switched on here', () => {
    it('is ok in a capable browser with a service worker', async () => {
        stubBrowser();
        const { pushSupportHere } = await loadPush();

        expect(await pushSupportHere({ platform: 'android', standalone: false })).toBe('ok');
    });

    it('does not look for a worker on an insecure origin', async () => {
        const browser = stubBrowser({ secure: false });
        const { pushSupportHere } = await loadPush();

        expect(await pushSupportHere({ platform: 'desktop', standalone: false })).toBe('insecure');
        expect(browser.container.getRegistration).not.toHaveBeenCalled();
    });
});

describe('installing the portal', () => {
    /** Chrome's `beforeinstallprompt`; the person answers `outcome` in its dialog. */
    function installEvent(outcome = 'accepted') {
        return Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
            prompt: vi.fn(async () => undefined),
            userChoice: Promise.resolve({ outcome }),
        });
    }

    it('keeps the browser\'s install dialog for "Install app" and opens it once', async () => {
        const browser = stubBrowser();
        const { canPrompt, captureInstallPrompt, promptInstall } = await loadInstall();

        captureInstallPrompt();
        const event = installEvent('accepted');
        browser.window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(canPrompt.value).toBe(true);
        expect(await promptInstall()).toBe('accepted');
        expect(event.prompt).toHaveBeenCalledTimes(1);
        expect(canPrompt.value).toBe(false);
        expect(await promptInstall()).toBe('unavailable');
    });

    it('says when the person closed the dialog or it could not open', async () => {
        const browser = stubBrowser();
        const { captureInstallPrompt, promptInstall } = await loadInstall();

        captureInstallPrompt();
        browser.window.dispatchEvent(installEvent('dismissed'));

        expect(await promptInstall()).toBe('dismissed');

        const broken = installEvent();
        broken.prompt.mockRejectedValue(new DOMException('Already shown', 'InvalidStateError'));
        browser.window.dispatchEvent(broken);

        expect(await promptInstall()).toBe('unavailable');
    });

    it('knows the portal was installed from this page', async () => {
        const browser = stubBrowser();
        const { appInstalled, canPrompt, captureInstallPrompt, promptInstall } = await loadInstall();

        captureInstallPrompt();
        captureInstallPrompt();
        browser.window.dispatchEvent(installEvent());
        browser.window.dispatchEvent(new Event('appinstalled'));

        expect(appInstalled.value).toBe(true);
        expect(canPrompt.value).toBe(false);
        expect(await promptInstall()).toBe('unavailable');
    });
});
