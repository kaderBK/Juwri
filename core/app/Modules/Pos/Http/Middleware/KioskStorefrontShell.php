<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Middleware;

use App\Models\Order;
use App\Services\FeatureAccess;
use App\Support\KioskContext;
use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * The kiosk's lock on the ordinary storefront. "Tap to order" leaves the attract page for the normal storefront
 * (/slug?kiosk=1), so the idle timer and the staff exit on the attract page were gone the moment a customer started.
 * For a browser session that the kiosk route marked (KioskContext, set server-side, never from a request field) this
 * adds one small script to the storefront / order-tracking page: after the store's idle time without a touch it forgets
 * the customer (cart, stored form data) and returns to the attract screen, and a "Staff exit" control asks for the
 * kiosk exit PIN (checked server-side by KioskController::exit). Any other visitor gets the page untouched.
 */
class KioskStorefrontShell
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || auth()->check()
            || ! str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }
        // P3-06: a kit theme draws its own kiosk screens (idle dialog, staff PIN pad, lock): no second shell on top of them
        if ($request->attributes->get('kiosk.themed') === true) {
            return $response;
        }
        $storeId = $this->storeId($request);
        if (! $storeId || ! KioskContext::active($storeId)
            || ! FeatureAccess::storeAllowsCached($storeId, 'kiosk') || empty(store_options($storeId, 'kiosk_enabled'))) {
            return $response;
        }
        $slug = Store::whereKey($storeId)->value('slug');
        $html = (string) $response->getContent();
        $at = strripos($html, '</body>');
        if (! $slug || $at === false) {
            return $response;
        }

        $script = view('kiosk.shell', [
            'slug' => $slug,
            'idleSeconds' => max(15, (int) (store_options($storeId, 'kiosk_idle_seconds') ?: 60)),
        ])->render();
        $response->setContent(substr($html, 0, $at).$script.substr($html, $at));

        return $response;
    }

    private function storeId(Request $request): ?int
    {
        $slug = $request->route('slug');
        if (is_string($slug)) {
            return Store::query()->idOrSlug($slug)->value('id');
        }
        $token = $request->route('token');

        return is_string($token) ? Order::where('track_token', $token)->value('store_id') : null;
    }
}
