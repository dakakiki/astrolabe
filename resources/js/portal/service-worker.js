/*
 * The client portal's service worker (Phase 9c, docs/spec/12, "Service worker").
 *
 * Served as /sw.js by PwaController, with `self.__PORTAL_SW__ = { version, precache }`
 * in front (the build's version and the offline page with its stylesheet). A plain
 * script, not bundled by Vite: it must come from the root to control the whole portal.
 *
 * - Keeps only the build's static files (hashed names under /build/) and the offline
 *   page. The portal's pages and its API always come from the network and are never
 *   stored: no client data is ever kept on the device (docs/spec/09, criterion 8;
 *   docs/spec/10, criterion 9).
 * - Without a connection a page shows the offline page instead.
 * - A new version waits until the portal asks for it (SKIP_WAITING after the person
 *   pressed "Reload"); the old version's files are then removed.
 * - Web push: shows the generic notice it was sent; tapping it opens the portal on
 *   this origin, which loads the details only then.
 */

const SETTINGS = self.__PORTAL_SW__ ?? { version: 'dev', precache: ['/offline'] };
const PREFIX = 'astrolabe-portal-';
const CACHE = `${PREFIX}${SETTINGS.version}`;
const OFFLINE = '/offline';
const ICON = '/icons/astrolabe-192.png';
const BADGE = '/icons/astrolabe-badge-96.png';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SETTINGS.precache)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys.filter((key) => key.startsWith(PREFIX) && key !== CACHE).map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // Pages: always the network; the offline page only when there is none.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(offlinePage));
        return;
    }

    // The build's files never change under the same name: the copy kept is good.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(fromCache(request));
    }

    // Everything else — the API above all — goes to the network untouched.
});

async function offlinePage() {
    const cache = await caches.open(CACHE);

    return (await cache.match(OFFLINE)) ?? Response.error();
}

async function fromCache(request) {
    const cache = await caches.open(CACHE);
    const kept = await cache.match(request);

    if (kept) return kept;

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        // A full disk only means the file comes from the network again next time.
        await cache.put(request, response.clone()).catch(() => {});
    }

    return response;
}

self.addEventListener('push', (event) => {
    const notice = readPush(event.data);

    event.waitUntil(
        self.registration.showNotification(notice.title, {
            body: notice.body,
            // A later notice about the same appointment replaces the earlier one and still
            // sounds, so a decline or a reminder is not missed. `renotify` without a tag throws.
            ...(notice.tag ? { tag: notice.tag, renotify: true } : {}),
            icon: ICON,
            badge: BADGE,
            data: { url: notice.url },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(samePath(event.notification.data?.url), self.location.origin).href;

    event.waitUntil(openPortal(target));
});

async function openPortal(target) {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

    if (open) {
        await open.focus();

        if ('navigate' in open) {
            try {
                await open.navigate(target);
            } catch {
                // An uncontrolled window cannot be sent elsewhere; focusing it is enough.
            }
        }

        return;
    }

    await self.clients.openWindow(target);
}

/** The push's title, line, tag and path — anything missing or odd falls back to safe values. */
function readPush(data) {
    let payload = {};

    try {
        payload = data ? data.json() : {};
    } catch {
        payload = {};
    }

    // Cut by character, not by UTF-16 unit, so that an emoji is never cut in half.
    const text = (value, length) => (typeof value === 'string' ? Array.from(value).slice(0, length).join('') : '');

    return {
        title: text(payload?.title, 120) || 'AstroLabe',
        body: text(payload?.body, 240),
        tag: text(payload?.tag, 64) || undefined,
        url: samePath(payload?.url),
    };
}

/**
 * Only a path on this origin ("/appointments"); anything else opens the portal's home.
 * Read the way the browser reads it: the URL parser drops tabs and line breaks and takes
 * "\" for "/", so "/\t/evil.test" is "//evil.test" — another site.
 */
function samePath(value) {
    if (typeof value !== 'string' || !value.startsWith('/')) return '/';

    let url;

    try {
        url = new URL(value, self.location.origin);
    } catch {
        return '/';
    }

    return url.origin === self.location.origin ? `${url.pathname}${url.search}${url.hash}` : '/';
}
