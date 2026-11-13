<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Middleware;

use App\Models\Order;
use App\Support\KioskContext;
use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-06: the server half of the kiosk lock. A browser session that the kiosk route marked (KioskContext, server-side, never from
 * a request field) may only reach the storefront of the store(s) it is a kiosk for: its page, its kiosk routes (start, setup,
 * reset, staff exit), its order endpoints (quote, send-order, coupon, slots, boot), the tracking page of its own orders and the
 * payment return pages. Everything else — the dashboard and the login, the marketing site, other stores, a customer account — is
 * refused: a page request goes back to the kiosk start screen, a script request gets a 403. The staff exit PIN (KioskController::exit)
 * ends the lock. The theme JS also locks links / back / long-press; this is the part a customer cannot switch off.
 *
 * A signed-in user (the owner previewing the kiosk page on their own browser) is never locked.
 */
class KioskLock
{
    /** route-name prefixes of the shopper-side payment and gateway return pages */
    private const PAYMENT_ROUTES = ['payment.', 'storeTransfer', 'applepay.'];

    public function handle(Request $request, Closure $next): Response
    {
        $marked = $request->hasSession() ? KioskContext::stores($request->session()) : [];
        if ($marked === [] || auth()->check()) {
            return $next($request);
        }

        $stores = Store::query()->whereIn('id', $marked)->get(['id', 'slug']);
        $live = $stores->filter(fn (Store $s) => KioskContext::on((int) $s->id, $request->session()))->values();
        if ($live->isEmpty()) {
            return $next($request); // the owner switched the kiosk off: no lock without a kiosk
        }

        $route = $request->route();
        if ($route === null || $this->allowed($request, $live->all())) {
            return $next($request);
        }

        $home = '/'.$live->first()->slug.'/kiosk';
        if ($request->expectsJson() || ! $request->isMethodSafe()) {
            return response()->json(['ok' => false, 'message' => ___('Not available at this kiosk.'), 'kiosk' => $home], 403);
        }

        return redirect($home);
    }

    /** @param  list<Store>  $stores */
    private function allowed(Request $request, array $stores): bool
    {
        $route = $request->route();
        $name = (string) $route->getName();

        // a store route: the page, its kiosk routes, quote / send-order / coupon / slots / boot / manifest of THIS store only
        foreach (['slug', 'store'] as $param) {
            $key = $route->parameter($param);
            if ($key === null) {
                continue;
            }
            $key = $key instanceof Store ? (string) $key->getKey() : (string) $key;
            foreach ($stores as $store) {
                if ($key === (string) $store->slug || $key === (string) $store->id || $key === (string) $store->getRouteKey()) {
                    return true;
                }
            }

            return false;
        }

        // the tracking page and its small actions: only an order of the kiosk's own store
        $token = $route->parameter('token');
        if (is_string($token) && str_starts_with($name, 'store.')) {
            $storeId = Order::query()->where('track_token', $token)->value('store_id');

            return $storeId !== null && in_array((int) $storeId, array_map(fn (Store $s) => (int) $s->id, $stores), true);
        }

        // the language switch and the shopper payment pages
        if (in_array($name, ['localize', 'localize.legacy', 'csp-report'], true) || $request->is('csp-report', 'csp-report/*')) {
            return true;
        }
        foreach (self::PAYMENT_ROUTES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
