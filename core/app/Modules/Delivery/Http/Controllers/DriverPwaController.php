<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;

/**
 * SYSTEMS-RUN E-P1: the driver PWA shell (browser, no Capacitor). Static-ish
 * HTML+JS that talks to the existing token-authed /api/v1/driver/* endpoints;
 * the token comes from the login link (?t=) or localStorage. Behind driver_app.
 */
class DriverPwaController extends Controller
{
    public function shell(Request $request)
    {
        return response()->view('driver.shell', [
            'token' => (string) $request->query('t', ''),
        ]);
    }

    public function manifest()
    {
        return response()->json([
            'name'             => 'Driver',
            'short_name'       => 'Driver',
            'start_url'        => url('/driver'),
            'display'          => 'standalone',
            'background_color' => '#0b1020',
            'theme_color'      => '#0b1020',
            'icons'            => [],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /**
     * Marketplace T4-3: the FLEET driver-app shell (also the web view the
     * Capacitor wrapper in driver-app/ loads). OTP login → token; talks to the
     * same /api/v1/driver/* surface as the store-driver shell.
     */
    public function fleetShell()
    {
        return response()->view('driver.fleet-app');
    }

    public function fleetManifest()
    {
        return response()->json([
            'name'             => 'Fusion Driver',
            'short_name'       => 'Driver',
            'start_url'        => url('/driver-app'),
            'display'          => 'standalone',
            'background_color' => '#0E1013',
            'theme_color'      => '#0E1013',
            'icons'            => [],
        ])->header('Content-Type', 'application/manifest+json');
    }

    public function serviceWorker()
    {
        // P6-237: allow-list cache. Only same-origin static assets (css/js/
        // fonts/images) are cached; HTML shells (may embed a token) and every
        // API/JSON response (customer names, addresses, phones) are never
        // cached. Old caches - incl. the PII-holding 'driver-v1' - are purged
        // on activate, and a 'driver-clear' message wipes everything (logout).
        $sw = <<<'JS'
        const CACHE = 'driver-static-v2';
        const STATIC_RE = /\.(?:css|js|mjs|woff2?|ttf|png|jpe?g|gif|svg|webp|ico)$/i;
        function cacheable(req) {
          if (req.method !== 'GET') return false;
          const url = new URL(req.url);
          if (url.origin !== self.location.origin) return false;
          if (url.pathname.startsWith('/api/') || url.pathname.endsWith('/sw.js')) return false;
          if (url.search !== '') return false;
          if (req.mode === 'navigate') return false;
          return STATIC_RE.test(url.pathname);
        }
        function purge(keep) {
          return caches.keys().then(keys => Promise.all(keys.filter(k => k !== keep).map(k => caches.delete(k))));
        }
        self.addEventListener('install', e => self.skipWaiting());
        self.addEventListener('activate', e => e.waitUntil(purge(CACHE).then(() => self.clients.claim())));
        self.addEventListener('message', e => {
          if (e.data === 'driver-clear') e.waitUntil(purge(null));
        });
        self.addEventListener('fetch', e => {
          if (!cacheable(e.request)) return;
          e.respondWith(
            caches.open(CACHE).then(async cache => {
              try {
                const net = await fetch(e.request);
                const cc = net.headers.get('Cache-Control') || '';
                const ct = net.headers.get('Content-Type') || '';
                if (net.ok && !/no-store|private/i.test(cc) && !/json|html/i.test(ct)) {
                  cache.put(e.request, net.clone());
                }
                return net;
              } catch (err) {
                const hit = await cache.match(e.request);
                if (hit) return hit;
                throw err;
              }
            })
          );
        });
        JS;

        return response($sw)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'no-cache');
    }
}
