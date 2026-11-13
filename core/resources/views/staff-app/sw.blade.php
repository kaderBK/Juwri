{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- #100 staff PWA service worker. Served at /staff-app/sw.js (scope /staff-app). --}}
const SHELL = '/staff-app';
const CACHE = 'staff-app-v6';

self.addEventListener('install', (e) => {
    self.skipWaiting();
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll([SHELL, SHELL + '/manifest.webmanifest'])));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || !url.pathname.startsWith('/staff-app')) return;

    // Only the app HTML is the "shell". The manifest must NOT be treated as shell —
    // it is keyed separately and network-first below, else it would be served the
    // cached HTML and its response would overwrite the SHELL cache entry.
    const isShell = url.pathname === '/staff-app' || url.pathname === '/staff-app/';

    // Live data (orders/floor/kds/tasks/menu): NETWORK-FIRST so the phone reflects
    // the true state; fall back to the last cached copy (then empty) when offline.
    if (!isShell) {
        // Private payloads (order details w/ customer names, staff comms) are NOT
        // persisted to CacheStorage, so they can't be read from the device after a
        // shift ends / on a shared phone. They are network-only (empty when offline).
        const isPrivate = url.pathname === '/staff-app/orders'
            || url.pathname === '/staff-app/messages';
        e.respondWith(
            fetch(e.request).then((res) => {
                if (!isPrivate) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(e.request, copy));
                }
                return res;
            }).catch(() => (isPrivate
                ? Promise.resolve(null)
                : caches.match(e.request)).then((c) => c
                || new Response('{}', { headers: { 'Content-Type': 'application/json' } })))
        );
        return;
    }

    // Shell: stale-while-revalidate — serve the cached shell instantly but always
    // refresh it in the background, so a shipped shell change shows on next load
    // (no more manually clearing the SW to see UI updates).
    e.respondWith(
        caches.match(SHELL).then((cached) => {
            const network = fetch(e.request).then((res) => {
                const copy = res.clone();
                caches.open(CACHE).then((c) => c.put(SHELL, copy));
                return res;
            }).catch(() => cached);
            return cached || network;
        })
    );
});

self.addEventListener('push', (e) => {
    let data = { title: 'New order', body: '', url: '/staff-app' };
    try { data = Object.assign(data, e.data.json()); } catch (err) {}
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        tag: 'order',
        renotify: true,
        vibrate: [80, 40, 80],
        data: { url: data.url },
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const target = (e.notification.data && e.notification.data.url) || '/staff-app';
    e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
        for (const w of wins) { if (w.url.includes('/staff-app') && 'focus' in w) return w.focus(); }
        return clients.openWindow(target);
    }));
});
