<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\OwnerPwa;
use Juwri\Modules\Messaging\Models\PushSubscription;

/**
 * Owner dashboard PWA (rev/mobile-app/PWA-SPEC.md §3): manifest, service
 * worker, offline page, generated icons and owner web-push subscriptions.
 */
class OwnerPwaController extends Controller
{
    use ResolvesCurrentStore;

    public function manifest(): Response
    {
        $store = null;
        if (Auth::check()) {
            $current = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
            $store = $current instanceof Store ? $current : null;
        }

        return response(json_encode(OwnerPwa::manifest($store), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'private, max-age=3600');
    }

    public function icon(Request $request, int $size): Response
    {
        return response(OwnerPwa::renderIcon($size, $request->boolean('maskable')))
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function offline(): Response
    {
        return response()->view('owner.pwa.offline', [
            'brand' => brand_title(),
            'color' => OwnerPwa::themeColor(),
        ]);
    }

    /**
     * The service worker. It only answers top-level navigations (network-first,
     * offline page on failure) — no page or asset caching, so owners never get
     * pinned to an old layout. Push display + click routing live here too.
     */
    public function serviceWorker(): Response
    {
        $version = OwnerPwa::SW_VERSION;
        $offline = route('owner.pwa.offline', [], false);
        $icon = route('owner.pwa.icon', ['size' => 192], false);
        $prefixes = json_encode(OwnerPwa::dashboardPrefixes(), JSON_UNESCAPED_SLASHES);

        $js = <<<JS
const VERSION = '{$version}';
const OFFLINE_URL = '{$offline}';
const ICON = '{$icon}';
// First path segments of the owner dashboard. Storefronts, marketing and admin
// navigations are never intercepted (PWA-SPEC §3.2).
const DASHBOARD = new Set({$prefixes});

function isDashboard(url) {
  const u = new URL(url);
  return u.origin === self.location.origin && DASHBOARD.has(u.pathname.split('/')[1] || '');
}

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll([OFFLINE_URL, ICON])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    // Only this worker's own old caches: the staff app, POS and driver workers share
    // the origin's cache storage, and their offline shells must survive this update.
    await Promise.all(keys.filter((k) => k.startsWith('owner-pwa-') && k !== VERSION).map((k) => caches.delete(k)));
    // No navigation preload: the worker lets storefront navigations through untouched,
    // and a preload it never consumes would hit those pages twice.
    if (self.registration.navigationPreload) { try { await self.registration.navigationPreload.disable(); } catch (e) {} }
    await self.clients.claim();
  })());
});

// Dashboard navigations only: network first, branded offline page when the network is gone.
self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate' || event.request.method !== 'GET' || !isDashboard(event.request.url)) return;
  event.respondWith((async () => {
    try {
      const res = await fetch(event.request);
      // Another worker on this origin may have cleared its caches (and ours with
      // them): put the offline page back while the network is here.
      event.waitUntil(caches.open(VERSION).then(async (c) => { if (!(await c.match(OFFLINE_URL))) await c.addAll([OFFLINE_URL, ICON]); }).catch(() => {}));
      return res;
    } catch (e) {
      const cache = await caches.open(VERSION);
      return (await cache.match(OFFLINE_URL)) || Response.error();
    }
  })());
});

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: event.data ? event.data.text() : '' }; }
  // The web-push sender wraps the event fields in `data` and defaults the top-level
  // url to the STAFF app — owners route by owner_url, then the event's own url.
  const x = (data && typeof data.data === 'object' && data.data) ? data.data : data;
  const title = data.title || x.title || 'New notification';
  const url = x.owner_url || data.owner_url || x.url || (data.url && data.url !== '/staff-app' ? data.url : null) || '/dashboard';
  const orderId = x.order_id || data.order_id;
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || x.body || '',
    icon: ICON,
    badge: ICON,
    tag: x.tag || (orderId ? 'order-' + orderId : undefined),
    renotify: !!orderId,
    data: { url: url },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/dashboard', self.location.origin).href;
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if (new URL(w.url).origin === self.location.origin && 'focus' in w) {
        await w.focus();
        if ('navigate' in w) { try { await w.navigate(target); } catch (e) {} }
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});
JS;

        return response($js)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'no-cache')
            ->header('Service-Worker-Allowed', '/');
    }

    /** Owner web-push subscription for the dashboard's current store. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'string', 'url', 'max:1000', 'starts_with:https://'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:255'],
            'subscription.keys.auth' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $rid = $this->currentRestaurantId($request);
        abort_unless($rid > 0, 404);

        $sub = PushSubscription::storeWeb($data['subscription'], $rid, (int) Auth::id(), [
            'device_name' => $data['device_name'] ?? null,
        ]);

        return response()->json(['success' => true, 'id' => $sub->id]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        $removed = PushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', Auth::id())
            ->where('platform', 'web')
            ->delete();

        return response()->json(['success' => true, 'removed' => $removed > 0]);
    }
}
