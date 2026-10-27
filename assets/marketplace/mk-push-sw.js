// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* Fusion marketplace — mk-push-sw.js (L1 T1-5)
   Web Push handlers for shopper order-status notifications. Kept OUT of the
   shared sw.js (lead-owned); the lead wires it with a single line near the
   top of sw.js:

     importScripts('/assets/marketplace/mk-push-sw.js?v=1');

   Payload contract (SendShopperPushJob): {title, body, url}. */
self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { body: e.data && e.data.text() }; }
  var title = data.title || 'Order update';
  e.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: '/assets/marketplace/icons/icon-192.png',
    badge: '/assets/marketplace/icons/icon-192.png',
    data: { url: data.url || '/fusion' }
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/fusion';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].url.indexOf(url) !== -1 && 'focus' in list[i]) { return list[i].focus(); }
      }
      return clients.openWindow(url);
    })
  );
});
