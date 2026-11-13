// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* Fusion marketplace SW (P0-5): offline shell + last-city cache.
   PRECACHE list must stay in sync with asset ?v bumps. */
/* T1-5 shopper push handlers */
importScripts('/assets/marketplace/mk-push-sw.js?v=1');

/* Bumped per shell/markup change: the precached /fusion shell is a full HTML
   document, so it carries a
   session CSRF token and whatever markup shipped the day it was cached. A
   shell cached before the city-picker fix kept serving a dead "Deliver to"
   chip long after the fix deployed — bump VERSION whenever the shell's markup
   or its inline handlers change, not only when an asset ?v moves. */
var VERSION = 'mk-v37';
/* These ?v values must track config('marketplace.asset_v') — they had drifted
   to 33 while the app served 41, so the SW was precaching two URLs the page
   never requested. */
var PRECACHE = [
  '/fusion',
  '/assets/marketplace/mk.css?v=45',
  '/assets/marketplace/mk-core.js?v=45',
  '/assets/marketplace/icons/icon-192.png',
  '/assets/marketplace/icons/icon-512.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(VERSION).then(function (c) { return c.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET') { return; }

  // Last-city store list: network-first, cache fallback.
  if (url.pathname === '/fusion/stores') {
    e.respondWith(
      fetch(e.request).then(function (res) {
        var copy = res.clone();
        caches.open(VERSION).then(function (c) { c.put(e.request, copy); });
        return res;
      }).catch(function () { return caches.match(e.request); })
    );
    return;
  }

  // T6-4 offline: ANY fusion page navigation that fails offline falls back to
  // the cached /fusion shell (city/cart state lives server-side; the shell at
  // least renders navigation + an installable surface instead of a dino).
  if (e.request.mode === 'navigate' && url.pathname.indexOf('/fusion') === 0 && url.pathname !== '/fusion') {
    e.respondWith(
      fetch(e.request).catch(function () { return caches.match('/fusion'); })
    );
    return;
  }

  // Shell document: network-first (context cookie changes the page), cached
  // copy only as the offline fallback.
  if (url.pathname === '/fusion') {
    e.respondWith(
      fetch(e.request).then(function (res) {
        var copy = res.clone();
        caches.open(VERSION).then(function (c) { c.put(e.request, copy); });
        return res;
      }).catch(function () { return caches.match(e.request); })
    );
    return;
  }

  // Static assets: cache-first (?v busts).
  if (url.pathname.indexOf('/assets/marketplace/') === 0) {
    e.respondWith(
      caches.match(e.request).then(function (hit) { return hit || fetch(e.request); })
    );
  }
});
