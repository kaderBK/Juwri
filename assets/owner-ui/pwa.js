// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner dashboard PWA (rev/mobile-app/PWA-SPEC.md).
 * - registers the dashboard service worker (navigation-only, no page caching)
 * - marks installed (standalone) sessions with the `owner_pwa` cookie for usage stats
 * - drives the install / "turn on order alerts" card ([data-owner-pwa-card])
 * - keeps the owner's subscription on the store the dashboard is showing
 * - installed app: edge-to-edge viewport + a status/title bar that follows the
 *   dashboard's light/dark top bar
 * Loaded once in <head> (data-turbo-eval="false"); the card re-binds on turbo:load.
 * Never runs inside the native app (the layout omits the config meta there).
 */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="owner-pwa"]');
  if (!meta || !('serviceWorker' in navigator)) return;

  var cfg = {
    sw: meta.getAttribute('data-sw'),
    vapid: meta.getAttribute('data-vapid') || '',
    subscribe: meta.getAttribute('data-subscribe'),
    unsubscribe: meta.getAttribute('data-unsubscribe'),
  };
  var DISMISS_KEY = 'ownerPwaCardDismissedAt';
  var DISMISS_DAYS = 30;
  var VIEWS_KEY = 'ownerPwaViews';
  var SUB_STORE_KEY = 'ownerPwaSubStore';
  var deferredPrompt = null;
  var viewsThisTab = 0;

  // The config meta is re-rendered on every Turbo visit (the current store changes
  // with the dashboard's store switcher), so read the store fresh each time.
  function currentStore() {
    var m = document.querySelector('meta[name="owner-pwa"]');
    return m ? (m.getAttribute('data-store') || '0') : '0';
  }

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  }
  function isIos() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function pushSupported() {
    return cfg.vapid !== '' && 'PushManager' in window && 'Notification' in window;
  }
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }
  function store(key, val) {
    try { if (val === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, val); } } catch (e) { /* private mode */ }
  }
  function read(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  // Never on the first page view (PWA-SPEC §3.4): count views, show from the second.
  function countView() {
    viewsThisTab++;
    var stored = parseInt(read(VIEWS_KEY) || '0', 10) || 0;
    if (stored < 10) store(VIEWS_KEY, String(stored + 1));
  }
  function seenBefore() {
    return viewsThisTab > 1 || (parseInt(read(VIEWS_KEY) || '0', 10) || 0) > 1;
  }
  function dismissedRecently() {
    var at = parseInt(read(DISMISS_KEY) || '0', 10);
    return at > 0 && (Date.now() - at) < DISMISS_DAYS * 864e5;
  }
  function deviceName() {
    var ua = navigator.userAgent;
    var browser = /edg\//i.test(ua) ? 'Edge' : /crios|chrome/i.test(ua) ? 'Chrome' : /fxios|firefox/i.test(ua) ? 'Firefox' : /safari/i.test(ua) ? 'Safari' : 'Browser';
    var os = /iphone/i.test(ua) ? 'iPhone' : /ipad/i.test(ua) ? 'iPad' : /android/i.test(ua) ? 'Android' : /mac os/i.test(ua) ? 'Mac' : /windows/i.test(ua) ? 'Windows' : 'Web';
    return browser + ' · ' + os;
  }
  function urlB64ToUint8Array(base64) {
    var padding = '='.repeat((4 - (base64.length % 4)) % 4);
    var raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  // 1. Surface marker for usage stats (server reads the cookie; no PII). The
  //    server also uses it to render the installed app edge to edge.
  document.cookie = 'owner_pwa=' + (isStandalone() ? '1' : '0') + '; path=/; max-age=31536000; SameSite=Lax';

  // Installed app chrome. viewport-fit=cover makes the notch / home-indicator
  // insets real for the tab bar (the server adds it once it has seen the cookie;
  // this covers the very first launch). theme-color follows the top bar, so the
  // status/title bar matches light and dark — including an explicit dark choice
  // while the OS is light, which the media-query metas alone cannot express.
  function applyInstalledChrome() {
    if (isStandalone()) {
      var vp = document.querySelector('meta[name="viewport"]');
      if (vp && !/viewport-fit=cover/.test(vp.getAttribute('content') || '')) {
        vp.setAttribute('content', (vp.getAttribute('content') || 'width=device-width, initial-scale=1') + ', viewport-fit=cover');
      }
    }
    var bar = document.querySelector('.app-header') || document.body;
    if (!bar) return;
    var color = window.getComputedStyle(bar).backgroundColor;
    if (!color || color === 'transparent' || /rgba\(0, 0, 0, 0\)/.test(color)) return;
    document.querySelectorAll('meta[name="theme-color"]').forEach(function (m) { m.setAttribute('content', color); });
  }
  if (window.MutationObserver) {
    new MutationObserver(function () { applyInstalledChrome(); })
      .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-bs-theme'] });
  }

  // 2. Service worker.
  var swReady = navigator.serviceWorker.register(cfg.sw, { scope: '/' }).then(function () {
    return navigator.serviceWorker.ready;
  }).catch(function () { return null; });

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    renderCard();
  });
  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    renderCard();
  });

  function currentSubscription() {
    return swReady.then(function (reg) { return reg && reg.pushManager ? reg.pushManager.getSubscription() : null; });
  }

  function enableAlerts(btn) {
    if (btn) btn.disabled = true;
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('denied');
      return swReady;
    }).then(function (reg) {
      if (!reg) throw new Error('no-sw');
      return reg.pushManager.getSubscription().then(function (existing) {
        return existing || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8Array(cfg.vapid) });
      });
    }).then(function (sub) {
      return saveSubscription(sub);
    }).then(function () {
      renderCard();
    }).catch(function () {
      if (btn) btn.disabled = false;
      renderCard('error');
    });
  }

  function saveSubscription(sub) {
    var storeId = currentStore();
    return fetch(cfg.subscribe, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify({ subscription: sub.toJSON(), device_name: deviceName() }),
    }).then(function (r) {
      if (!r.ok) throw new Error('save');
      store(SUB_STORE_KEY, storeId);
    });
  }

  // Alerts follow the store the dashboard shows (PWA-SPEC §3.3): after a store
  // switch, re-save this browser's subscription so it moves to the new store.
  function syncStore() {
    if (!pushSupported() || Notification.permission !== 'granted') return;
    var storeId = currentStore();
    if (storeId === '0' || read(SUB_STORE_KEY) === storeId) return;
    currentSubscription().then(function (sub) { if (sub) saveSubscription(sub).catch(function () {}); });
  }

  function disableAlerts(btn) {
    if (btn) btn.disabled = true;
    return currentSubscription().then(function (sub) {
      if (!sub) return null;
      var endpoint = sub.endpoint;
      return sub.unsubscribe().then(function () {
        return fetch(cfg.unsubscribe, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
          body: JSON.stringify({ endpoint: endpoint }),
        });
      });
    }).then(function () { store(SUB_STORE_KEY, null); renderCard(); }).catch(function () { renderCard(); });
  }

  // 3. The card: exactly one state is shown (or none).
  function renderCard(flash) {
    var cards = document.querySelectorAll('[data-owner-pwa-card]');
    if (!cards.length) return;

    currentSubscription().then(function (sub) {
      var state = 'none';
      var standalone = isStandalone();
      var perm = ('Notification' in window) ? Notification.permission : 'unsupported';

      if (flash === 'error') {
        state = perm === 'denied' ? 'blocked' : 'error';
      } else if (sub && perm === 'granted') {
        state = 'on';
      } else if (!standalone && deferredPrompt) {
        state = 'install';
      } else if (!standalone && isIos()) {
        state = 'ios';
      } else if (pushSupported() && perm !== 'denied') {
        state = 'alerts';
      } else if (perm === 'denied') {
        state = 'blocked';
      }

      cards.forEach(function (card) {
        var persistent = card.hasAttribute('data-owner-pwa-persistent');
        var hide = state === 'none' || (!persistent && (state === 'on' || dismissedRecently() || !seenBefore()));
        card.hidden = hide;
        card.querySelectorAll('[data-state]').forEach(function (el) {
          el.hidden = el.getAttribute('data-state') !== state;
        });
      });
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-owner-pwa-action]') : null;
    if (!t) return;
    var action = t.getAttribute('data-owner-pwa-action');
    if (action === 'install' && deferredPrompt) {
      e.preventDefault();
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () { deferredPrompt = null; renderCard(); });
    } else if (action === 'alerts') {
      e.preventDefault();
      enableAlerts(t);
    } else if (action === 'alerts-off') {
      e.preventDefault();
      disableAlerts(t);
    } else if (action === 'dismiss') {
      e.preventDefault();
      store(DISMISS_KEY, String(Date.now()));
      renderCard();
    }
  });

  // Permission already granted (e.g. allowed after the prompt, or from browser
  // settings) but this browser has no subscription yet → subscribe silently.
  // No prompt is shown, so no user gesture is needed.
  function autoSubscribe() {
    if (!pushSupported() || Notification.permission !== 'granted') return;
    currentSubscription().then(function (sub) { if (!sub) enableAlerts(null); else syncStore(); });
  }
  autoSubscribe();

  // Once per page view. Turbo swaps <body> on every visit, so a body we have
  // already handled is the same view. (Turbo's first turbo:load can fire before
  // this deferred script runs, hence the immediate call as well.)
  function onPage() {
    var body = document.body;
    if (!body || body.__ownerPwaSeen) return;
    body.__ownerPwaSeen = true;
    countView();
    applyInstalledChrome();
    syncStore();
    renderCard();
  }
  document.addEventListener('turbo:load', onPage);
  document.addEventListener('turbo:render', applyInstalledChrome);
  if (document.readyState !== 'loading') { onPage(); } else { document.addEventListener('DOMContentLoaded', onPage); }
})();
