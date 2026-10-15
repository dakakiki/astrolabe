import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The portal's service worker is a plain script served as /sw.js (PwaController),
 * so it is read as text and run against a fake worker global: events are dispatched
 * by hand and what they wait for or respond with is awaited here.
 */
const SOURCE = readFileSync(new URL('../../resources/js/portal/service-worker.js', import.meta.url), 'utf8');

const ORIGIN = 'https://portal.example.test';
const CACHE = 'astrolabe-portal-abc123';
const ICON = '/icons/astrolabe-192.png';
const BADGE = '/icons/astrolabe-badge-96.png';

/** A response as the browser's fetch gives it for this origin ("basic"), or of the type given. */
function reply(body, { status = 200, type = 'basic', contentType = 'text/plain' } = {}) {
    const response = new Response(body, { status, headers: { 'Content-Type': contentType } });

    Object.defineProperty(response, 'type', { value: type });

    return response;
}

/** CacheStorage and Cache, kept in memory and keyed by absolute URL. */
function fakeCaches(network) {
    const stores = new Map();
    const key = (request) => new URL(typeof request === 'string' ? request : request.url, ORIGIN).href;

    const cache = () => {
        const entries = new Map();

        return {
            entries,
            async match(request) {
                return entries.get(key(request))?.clone();
            },
            async put(request, response) {
                entries.set(key(request), response);
            },
            // Like the browser's: all or nothing, and a response that is not ok fails it.
            async addAll(requests) {
                const responses = await Promise.all(
                    requests.map((request) => network({ url: key(request), method: 'GET', mode: 'cors' })),
                );

                if (responses.some((response) => !response.ok)) {
                    throw new TypeError('Request failed');
                }

                requests.forEach((request, index) => entries.set(key(request), responses[index]));
            },
        };
    };

    return {
        stores,
        async open(name) {
            if (!stores.has(name)) stores.set(name, cache());

            return stores.get(name);
        },
        async keys() {
            return [...stores.keys()];
        },
        async delete(name) {
            return stores.delete(name);
        },
        async match(request) {
            for (const store of stores.values()) {
                const found = await store.match(request);

                if (found) return found;
            }

            return undefined;
        },
    };
}

let worker;

/** Runs the worker's source in a fresh fake global; `settings` is what PwaController puts in front. */
function boot(settings = { version: 'abc123', precache: ['/offline', '/build/assets/portal-x.css'] }) {
    const handlers = {};
    const files = {
        '/offline': () => reply('<p>You are offline</p>', { contentType: 'text/html' }),
        '/build/assets/portal-x.css': () => reply('body{}', { contentType: 'text/css' }),
    };
    const state = { online: true };

    const fetch = vi.fn(async (request) => {
        if (!state.online) throw new TypeError('Failed to fetch');

        const path = new URL(request.url).pathname;

        return files[path]?.() ?? reply('Not found', { status: 404 });
    });
    const caches = fakeCaches(fetch);
    const windows = [];
    const self = {
        location: new URL(`${ORIGIN}/sw.js`),
        addEventListener: (type, handler) => (handlers[type] ??= []).push(handler),
        registration: { showNotification: vi.fn(async () => undefined) },
        skipWaiting: vi.fn(async () => undefined),
        clients: {
            claim: vi.fn(async () => undefined),
            matchAll: vi.fn(async () => windows),
            openWindow: vi.fn(async () => null),
        },
    };

    if (settings) self.__PORTAL_SW__ = settings;

    new Function('self', 'caches', 'fetch', 'Response', SOURCE)(self, caches, fetch, Response);

    /** Fires an event; `done` settles with everything passed to waitUntil, `response` is what respondWith got. */
    const dispatch = (type, init = {}) => {
        const waiting = [];
        let response;
        const event = {
            ...init,
            waitUntil: (promise) => waiting.push(Promise.resolve(promise)),
            respondWith: (promise) => (response = Promise.resolve(promise)),
        };

        for (const handler of handlers[type] ?? []) handler(event);

        return { done: Promise.all(waiting), response };
    };

    return { self, caches, fetch, files, state, windows, dispatch };
}

const request = (path, { method = 'GET', mode = 'cors' } = {}) => ({
    url: path.startsWith('http') ? path : `${ORIGIN}${path}`,
    method,
    mode,
});

const install = () => worker.dispatch('install').done;

const fetchEvent = (path, options) => worker.dispatch('fetch', { request: request(path, options) }).response;

beforeEach(() => {
    worker = boot();
});

describe('install', () => {
    it("keeps the offline page and its stylesheet under this build's name", async () => {
        await install();

        expect(await worker.caches.keys()).toEqual([CACHE]);
        expect([...worker.caches.stores.get(CACHE).entries.keys()]).toEqual([
            `${ORIGIN}/offline`,
            `${ORIGIN}/build/assets/portal-x.css`,
        ]);
    });

    it('waits for the portal to ask before taking over', async () => {
        await install();

        expect(worker.self.skipWaiting).not.toHaveBeenCalled();
    });

    it('fails when a file cannot be kept, so the old version stays', async () => {
        delete worker.files['/build/assets/portal-x.css'];

        await expect(install()).rejects.toThrow();
    });

    it('keeps just the offline page without settings from the server', async () => {
        worker = boot(null);

        await install();

        expect(await worker.caches.keys()).toEqual(['astrolabe-portal-dev']);
        expect([...worker.caches.stores.get('astrolabe-portal-dev').entries.keys()]).toEqual([`${ORIGIN}/offline`]);
    });
});

describe('activate', () => {
    it("removes older versions' files, keeps other caches and takes over open pages", async () => {
        for (const name of ['astrolabe-portal-old111', CACHE, 'astrolabe-portal-dev', 'someone-else']) {
            await worker.caches.open(name);
        }

        let keptWhenClaimed;
        worker.self.clients.claim.mockImplementation(async () => {
            keptWhenClaimed = await worker.caches.keys();
        });

        await worker.dispatch('activate').done;

        expect(await worker.caches.keys()).toEqual([CACHE, 'someone-else']);
        expect(keptWhenClaimed).toEqual([CACHE, 'someone-else']);
        expect(worker.self.clients.claim).toHaveBeenCalledTimes(1);
    });
});

describe('message', () => {
    it('takes over when the portal asks for the new version', () => {
        worker.dispatch('message', { data: { type: 'SKIP_WAITING' } });

        expect(worker.self.skipWaiting).toHaveBeenCalledTimes(1);
    });

    it('ignores any other message', () => {
        for (const data of [{ type: 'OTHER' }, 'SKIP_WAITING', null, undefined, 42]) {
            worker.dispatch('message', { data });
        }
        worker.dispatch('message');

        expect(worker.self.skipWaiting).not.toHaveBeenCalled();
    });
});

describe('fetch', () => {
    it('leaves anything but GET alone', () => {
        expect(fetchEvent('/build/assets/app-1.js', { method: 'POST' })).toBeUndefined();
        expect(fetchEvent('/api/portal/v1/profile', { method: 'PUT' })).toBeUndefined();
        expect(fetchEvent('/sign-in', { method: 'POST', mode: 'navigate' })).toBeUndefined();
    });

    it('leaves other origins alone', () => {
        expect(fetchEvent('https://fonts.example.test/build/assets/font.woff2')).toBeUndefined();
        expect(fetchEvent('https://astrolabe.example.test/', { mode: 'navigate' })).toBeUndefined();
    });

    it('never touches the API', () => {
        expect(fetchEvent('/api/portal/v1/home')).toBeUndefined();
        expect(fetchEvent('/api/portal/v1/appointments?page=2')).toBeUndefined();
        expect(worker.fetch).not.toHaveBeenCalled();
    });

    it('leaves the other files of the origin to the browser', () => {
        for (const path of [
            '/icons/x.png',
            '/manifest.webmanifest',
            '/sw.js',
            '/offline',
            '/build',
            '/buildings/a.js',
        ]) {
            expect(fetchEvent(path)).toBeUndefined();
        }
    });

    it('loads a page from the network and keeps no copy of it', async () => {
        await install();
        worker.files['/appointments'] = () => reply('<p>Your appointments</p>', { contentType: 'text/html' });

        const response = await fetchEvent('/appointments', { mode: 'navigate' });

        expect(await response.text()).toBe('<p>Your appointments</p>');
        expect(await worker.caches.match(`${ORIGIN}/appointments`)).toBeUndefined();
        expect([...worker.caches.stores.get(CACHE).entries.keys()]).toEqual([
            `${ORIGIN}/offline`,
            `${ORIGIN}/build/assets/portal-x.css`,
        ]);
    });

    it('shows the offline page when there is no connection', async () => {
        await install();
        worker.state.online = false;

        const response = await fetchEvent('/appointments', { mode: 'navigate' });

        expect(await response.text()).toBe('<p>You are offline</p>');
    });

    it('fails like the network when the offline page was never kept', async () => {
        worker.state.online = false;

        const response = await fetchEvent('/appointments', { mode: 'navigate' });

        expect(response.type).toBe('error');
    });

    it("fetches a build file once and keeps it under this build's name", async () => {
        worker.files['/build/assets/app-1.js'] = () => reply('console.log(1)', { contentType: 'text/javascript' });

        const response = await fetchEvent('/build/assets/app-1.js');

        expect(await response.text()).toBe('console.log(1)');
        expect(worker.fetch).toHaveBeenCalledTimes(1);
        expect(await (await worker.caches.stores.get(CACHE).match(`${ORIGIN}/build/assets/app-1.js`)).text()).toBe(
            'console.log(1)',
        );
    });

    it('serves a kept build file without the network', async () => {
        const cache = await worker.caches.open(CACHE);
        await cache.put(`${ORIGIN}/build/assets/app-1.js`, reply('kept'));
        worker.state.online = false;

        const response = await fetchEvent('/build/assets/app-1.js');

        expect(await response.text()).toBe('kept');
        expect(worker.fetch).not.toHaveBeenCalled();
    });

    it('keeps no failed or opaque responses', async () => {
        worker.files['/build/assets/opaque.js'] = () => reply('?', { type: 'opaque' });

        const missing = await fetchEvent('/build/assets/missing.js');
        const opaque = await fetchEvent('/build/assets/opaque.js');

        expect(missing.status).toBe(404);
        expect(opaque.type).toBe('opaque');
        expect(worker.caches.stores.get(CACHE).entries.size).toBe(0);
    });

    it('still serves a build file when it cannot be kept', async () => {
        worker.files['/build/assets/app-1.js'] = () => reply('console.log(1)');
        const cache = await worker.caches.open(CACHE);
        cache.put = vi.fn(async () => {
            throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
        });

        const response = await fetchEvent('/build/assets/app-1.js');

        expect(await response.text()).toBe('console.log(1)');
        expect(cache.put).toHaveBeenCalledTimes(1);
    });
});

describe('push', () => {
    const data = (text) => ({
        text: () => text,
        json: () => JSON.parse(text),
    });
    const push = (payload) => worker.dispatch('push', { data: payload === undefined ? null : data(payload) }).done;
    const shown = () => worker.self.registration.showNotification.mock.calls.at(-1);

    it('shows the notice it was sent', async () => {
        await push(
            JSON.stringify({
                title: 'Your booking is confirmed',
                body: 'Tomorrow at 3:00 PM',
                tag: 'appointment-7',
                url: '/appointments',
            }),
        );

        expect(worker.self.registration.showNotification).toHaveBeenCalledWith('Your booking is confirmed', {
            body: 'Tomorrow at 3:00 PM',
            tag: 'appointment-7',
            renotify: true,
            icon: ICON,
            badge: BADGE,
            data: { url: '/appointments' },
        });
    });

    it('falls back to a plain notice for a missing or odd payload', async () => {
        for (const payload of [undefined, 'not json', '42', '"text"', 'null', '[1, 2]', '{"title": 5, "url": 7}']) {
            await push(payload);

            expect(shown()).toEqual(['AstroLabe', { body: '', icon: ICON, badge: BADGE, data: { url: '/' } }]);
            expect(shown()[1]).not.toHaveProperty('tag');
            expect(shown()[1]).not.toHaveProperty('renotify');
        }

        expect(worker.self.registration.showNotification).toHaveBeenCalledTimes(7);
    });

    it('sounds again for a later notice about the same appointment, never without a tag', async () => {
        await push(JSON.stringify({ title: 'Your booking is confirmed', tag: 'appointment-7' }));
        await push(JSON.stringify({ title: 'Reminder: your appointment is tomorrow', tag: 'appointment-7' }));

        for (const [, options] of worker.self.registration.showNotification.mock.calls) {
            expect(options).toMatchObject({ tag: 'appointment-7', renotify: true });
        }

        // The browser throws a TypeError for renotify with an empty tag.
        for (const tag of ['', null, 7, undefined]) {
            await push(JSON.stringify({ title: 'Hi', tag }));

            expect(shown()[1]).not.toHaveProperty('renotify');
        }
    });

    it('opens only a path on this origin', async () => {
        for (const url of [
            'https://evil.test/x',
            '//evil.test',
            '/\\evil.test',
            'javascript:alert(1)',
            '/\t/evil.test',
            '/\n/evil.test',
            `${ORIGIN}/appointments`,
        ]) {
            await push(JSON.stringify({ title: 'Hi', url }));

            expect(shown()[1].data).toEqual({ url: '/' });
        }

        await push(JSON.stringify({ title: 'Hi', url: '/appointments?tab=past#next' }));

        expect(shown()[1].data).toEqual({ url: '/appointments?tab=past#next' });
    });

    it('cuts over-long texts', async () => {
        await push(JSON.stringify({ title: 'T'.repeat(200), body: 'B'.repeat(500), tag: 'g'.repeat(100) }));

        const [title, options] = shown();

        expect(title).toBe('T'.repeat(120));
        expect(options.body).toBe('B'.repeat(240));
        expect(options.tag).toBe('g'.repeat(64));
    });

    it('never cuts a character in half', async () => {
        await push(JSON.stringify({ title: `${'T'.repeat(119)}🌙 and more` }));

        expect(shown()[0]).toBe(`${'T'.repeat(119)}🌙`);
    });
});

describe('notificationclick', () => {
    const click = (data = { url: '/appointments' }) => {
        const notification = { data, close: vi.fn() };

        return { notification, done: worker.dispatch('notificationclick', { notification }).done };
    };
    const portalWindow = (url = `${ORIGIN}/home`) => ({
        url,
        focus: vi.fn(async () => undefined),
        navigate: vi.fn(async () => undefined),
    });

    it('closes the notice', async () => {
        const { notification, done } = click();
        await done;

        expect(notification.close).toHaveBeenCalledTimes(1);
    });

    it('brings an open portal window forward and sends it to the page', async () => {
        const open = portalWindow();
        worker.windows.push(open);

        await click().done;

        expect(worker.self.clients.matchAll).toHaveBeenCalledWith({ type: 'window', includeUncontrolled: true });
        expect(open.focus).toHaveBeenCalledTimes(1);
        expect(open.navigate).toHaveBeenCalledWith(`${ORIGIN}/appointments`);
        expect(worker.self.clients.openWindow).not.toHaveBeenCalled();
    });

    it('only focuses a window it cannot send elsewhere', async () => {
        const open = portalWindow();
        open.navigate.mockRejectedValue(new TypeError('Not controlled'));
        worker.windows.push(open);

        await click().done;

        expect(open.focus).toHaveBeenCalledTimes(1);
        expect(worker.self.clients.openWindow).not.toHaveBeenCalled();
    });

    it('opens the portal when no window is open', async () => {
        await click().done;

        expect(worker.self.clients.openWindow).toHaveBeenCalledWith(`${ORIGIN}/appointments`);
    });

    it("opens the portal's home for a path from elsewhere", async () => {
        for (const url of ['https://evil.test/x', '/\t/evil.test', null]) {
            await click({ url }).done;

            expect(worker.self.clients.openWindow).toHaveBeenLastCalledWith(`${ORIGIN}/`);
        }

        await click(null).done;

        expect(worker.self.clients.openWindow).toHaveBeenLastCalledWith(`${ORIGIN}/`);
    });

    it('ignores a window on another origin', async () => {
        const other = portalWindow('https://evil.test/');
        worker.windows.push(other);

        await click().done;

        expect(other.focus).not.toHaveBeenCalled();
        expect(other.navigate).not.toHaveBeenCalled();
        expect(worker.self.clients.openWindow).toHaveBeenCalledWith(`${ORIGIN}/appointments`);
    });
});
