// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront service worker for the kit themes (P3-03; replaces /sw-menu.js for them).
   THEME-CONTRACT §5.5: one worker per store address (scope "/" on the store's own host, "/{slug}" on the shared
   path-form host), never shared between stores. It keeps
     - the app shell: core storefront JS, theme CSS/JS, fonts, images (cache-first, bounded),
     - a versioned menu snapshot (`snapshot.json` of the store; replaced when its version changes),
     - the last viewed track page (one entry),
     - pages, network-first, so a shopper who lost the signal still sees the menu.
   Registration URL: /sw-store.js?v=<app version>&s=<store slug>&oq=<1|0>
     v   rotates the caches on deploy;
     s   names the store (cache + database names);
     oq  the EFFECTIVE offline-queue switch (platform AND reseller AND owner). A worker installed with oq=0 drops
         every unsent queued order from this store's database and tells the open pages (kill switch, any level).
   Never caches a response the server marked no-store, nor a private one unless the server vouches that the page is
   anonymous (X-Juwri-Anonymous: 1), nor a request outside this worker's scope.
   The offline order queue itself lives in the page (IndexedDB `juwri-sf:<host>:<slug>`); this worker only
   clears it. Cart and queue are never read across stores: the database name carries store and host. */
const P = new URL(self.location).searchParams;
const VERSION = P.get('v') || '1';
const SLUG = P.get('s') || '';
const QUEUE_ON = P.get('oq') !== '0';
const SCOPE = new URL(self.registration.scope).pathname;
const SHELL = 'jw-shell-v' + VERSION + '-' + SCOPE;
const SNAP = 'jw-snap-' + SCOPE;
const TRACK = 'jw-track-' + SCOPE;
const PREFIX = ['jw-shell-v', 'jw-snap-', 'jw-track-'];
const MAX_SHELL = 120;
const OFFLINE_URL = '/offline.html';
const ASSET = /\.(css|js|mjs|png|jpe?g|gif|webp|avif|woff2?|ttf|svg|ico)(\?|$)/i;
const CORE = ['core', 'util', 'db', 'api', 'cart', 'queue', 'sheets', 'announcer', 'kiosk', 'loyalty', 'dom'].map((n) => '/assets/storefront/' + n + '.js');

function inScope(url) { return url.indexOf(self.registration.scope) === 0; }

function cacheable(request, response) {
    if (!response || response.status !== 200 || response.type === 'opaque') return false;
    if (!inScope(request.url) && new URL(request.url).origin !== self.location.origin) return false;
    const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
    if (cc.indexOf('no-store') !== -1) return false;
    if (cc.indexOf('private') === -1) return true;
    // Laravel marks every session response "private". A storefront page that holds nothing personal says so itself
    // (X-Juwri-Anonymous: set by the server for a visitor signed in as nobody, and for the shopper's own track page).
    return response.headers.get('X-Juwri-Anonymous') === '1' && (request.mode === 'navigate' || (request.headers.get('accept') || '').indexOf('text/html') !== -1);
}

function trim(cache, max) {
    cache.keys().then((keys) => {
        const rest = keys.filter((k) => {
            const p = new URL(k.url).pathname;
            return p !== OFFLINE_URL && CORE.indexOf(p) === -1;
        });
        for (let i = 0; i < rest.length - max; i++) cache.delete(rest[i]);
    });
}

async function remember(name, request, response, max) {
    if (!cacheable(request, response)) return;
    const copy = response.clone();
    const cache = await caches.open(name);
    await cache.put(request, copy);
    if (max) trim(cache, max);
}

/** The kill switch: delete every unsent order of this store from its database and tell the open pages. */
function dropQueue() {
    return new Promise((resolve) => {
        if (!SLUG || !self.indexedDB) { resolve(0); return; }
        let req;
        try { req = indexedDB.open('juwri-sf:' + self.location.host + ':' + SLUG, 1); } catch (e) { resolve(0); return; }
        req.onupgradeneeded = () => { req.transaction.abort(); }; // never create a database here
        req.onerror = () => resolve(0);
        req.onsuccess = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('queue')) { db.close(); resolve(0); return; }
            const tx = db.transaction('queue', 'readwrite');
            const store = tx.objectStore('queue');
            let dropped = 0;
            store.openCursor().onsuccess = (e) => {
                const cur = e.target.result;
                if (!cur) return;
                if (!cur.value || cur.value.state !== 'placed') { cur.delete(); dropped++; }
                cur.continue();
            };
            tx.oncomplete = () => { db.close(); resolve(dropped); };
            tx.onerror = tx.onabort = () => { db.close(); resolve(0); };
        };
    });
}

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(SHELL).then((c) => c.addAll([OFFLINE_URL].concat(CORE))).catch(() => {}));
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil((async () => {
        // Only this scope's older versions go; other stores' workers keep their own caches (Cache Storage is per origin).
        const suffix = '-' + SCOPE;
        for (const k of await caches.keys()) {
            const mine = PREFIX.some((p) => k.indexOf(p) === 0) && k.slice(-suffix.length) === suffix;
            if (mine && k !== SHELL && k !== SNAP && k !== TRACK) await caches.delete(k);
        }
        await self.clients.claim();
        if (!QUEUE_ON) {
            const count = await dropQueue();
            for (const c of await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) {
                c.postMessage({ type: 'juwri:queue-dropped', count });
            }
        }
    })());
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET' || req.url.indexOf('http') !== 0) return;
    const url = new URL(req.url);

    // The menu snapshot: network first, the cached copy when offline; the cache keeps ONE version.
    if (/\/storefront\/snapshot\.json$/.test(url.pathname) && inScope(req.url)) {
        e.respondWith(fetch(req).then(async (res) => {
            if (cacheable(req, res)) {
                const cache = await caches.open(SNAP);
                for (const k of await cache.keys()) await cache.delete(k);
                await cache.put(req, res.clone());
            }
            return res;
        }).catch(() => caches.open(SNAP).then((c) => c.match(req, { ignoreSearch: true })).then((r) => r || Response.error())));
        return;
    }

    if (url.origin === self.location.origin && ASSET.test(url.pathname + url.search)) {
        e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => { remember(SHELL, req, res, MAX_SHELL); return res; })));
        return;
    }

    if (req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') !== -1) {
        const isTrack = /\/order\/track\//.test(url.pathname);
        e.respondWith(fetch(req).then(async (res) => {
            if (cacheable(req, res) && inScope(req.url)) {
                if (isTrack) {
                    const t = await caches.open(TRACK);
                    for (const k of await t.keys()) await t.delete(k); // the last viewed track page only
                    await t.put(req, res.clone());
                } else {
                    remember(SHELL, req, res, MAX_SHELL);
                }
            }
            return res;
        }).catch(async () => (await caches.match(req)) || (await caches.match(OFFLINE_URL)) || Response.error()));
    }
});

/* Customer Web Push (closed-tab "order ready"): unchanged from /sw-menu.js — PII-free payload. */
self.addEventListener('push', (e) => {
    let data = { title: 'Order update', body: '', url: SCOPE };
    try { data = Object.assign(data, e.data.json()); } catch (err) { /* plain payload */ }
    e.waitUntil(self.registration.showNotification(data.title, { body: data.body, tag: 'mb-order', renotify: true, vibrate: [80, 40, 80], data: { url: data.url } }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const target = (e.notification.data && e.notification.data.url) || SCOPE;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
        for (const w of wins) if (w.url.indexOf(target) !== -1 && 'focus' in w) return w.focus();
        return self.clients.openWindow(target);
    }));
});
