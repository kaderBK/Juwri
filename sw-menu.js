// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* #25: juwri PWA Service Worker — cache-first for assets, network-first for pages.
   F14-1: the cache name is versioned from the ?v= on the registration URL (bumped
   with appinfo.version on deploy), so activate() auto-purges the old cache; a
   precached offline.html is served when a page fetch fails with nothing cached;
   the runtime cache is trimmed so it can't grow unbounded. */
const CACHE = 'mb-menu-v' + (new URL(self.location).searchParams.get('v') || '1') + '-' + new URL(self.registration.scope).pathname;
const ASSET_EXTS = /\.(css|js|png|jpg|jpeg|gif|webp|woff2?|ttf|eot|svg|ico)$/i;
const OFFLINE_URL = '/offline.html';
const MAX_ENTRIES = 60; // cap the runtime cache

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE).then(function (c) { return c.add(OFFLINE_URL); }).catch(function () {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            // Cache Storage is per ORIGIN: on the shared path-form host other stores' workers keep their own
            // caches (suffix = their scope). Drop only older versions of this scope + unscoped legacy caches.
            var suffix = '-' + new URL(self.registration.scope).pathname;
            return Promise.all(keys.filter(function (k) {
                if (k === CACHE || k.indexOf('mb-menu-v') !== 0) return false;
                return k.slice(-suffix.length) === suffix || k.indexOf('-/') === -1;
            }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

/* Keep the runtime cache bounded: after a put, trim the oldest entries beyond
   MAX_ENTRIES, never evicting the precached offline page. */
function trim(cache) {
    cache.keys().then(function (keys) {
        var rest = keys.filter(function (k) { return new URL(k.url).pathname !== OFFLINE_URL; });
        if (rest.length <= MAX_ENTRIES) return;
        for (var i = 0; i < rest.length - MAX_ENTRIES; i++) { cache.delete(rest[i]); }
    });
}

/* P1-20 (J28): one worker per store address. On a store's own origin (custom domain / subdomain) the
   scope is "/"; on the shared path-form host it is "/{slug}", so a worker only ever caches its own store's
   pages. Never cache a response the server marked private/no-store (signed-in pages, drafts, checkout) or a
   request outside this worker's scope. */
function cacheable(request, response) {
    if (!response || response.status !== 200) return false;
    if (request.url.indexOf(self.registration.scope) !== 0) return false;
    var cc = (response.headers.get('Cache-Control') || '').toLowerCase();
    return cc.indexOf('no-store') === -1 && cc.indexOf('private') === -1;
}

function cachePut(request, response) {
    if (!cacheable(request, response)) return;
    var clone = response.clone();
    caches.open(CACHE).then(function (c) { c.put(request, clone); trim(c); });
}

self.addEventListener('fetch', function (e) {
    var url = e.request.url;
    if (e.request.method !== 'GET') return;
    if (!url.startsWith('http')) return;

    if (ASSET_EXTS.test(url)) {
        // Cache-first for static assets.
        e.respondWith(
            caches.match(e.request).then(function (cached) {
                if (cached) return cached;
                return fetch(e.request).then(function (response) {
                    cachePut(e.request, response);
                    return response;
                });
            })
        );
    } else {
        // Network-first for pages — fall back to cache, then to the offline page.
        e.respondWith(
            fetch(e.request).then(function (response) {
                cachePut(e.request, response);
                return response;
            }).catch(function () {
                return caches.match(e.request).then(function (cached) {
                    return cached || caches.match(OFFLINE_URL);
                });
            })
        );
    }
});

/* P1: customer Web Push — the closed-tab "order ready" notification. The page
   subscribes via pushManager (behind a user gesture) and POSTs the subscription
   to order/track/{token}/push-subscribe; the server pushes on meaningful status
   changes. Payload is PII-free: a store name + a status line + the track URL. */
self.addEventListener('push', function (e) {
    var data = { title: 'Order update', body: '', url: '/' };
    try { data = Object.assign(data, e.data.json()); } catch (err) {}
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        tag: 'mb-order',
        renotify: true,
        vibrate: [80, 40, 80],
        data: { url: data.url },
    }));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var target = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (wins) {
        for (var i = 0; i < wins.length; i++) {
            var w = wins[i];
            if (w.url.indexOf(target) !== -1 && 'focus' in w) { return w.focus(); }
        }
        return clients.openWindow(target);
    }));
});
