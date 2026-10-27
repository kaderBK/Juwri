<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Juwri\Core\Support\OwnerApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner mobile app, server item S4 (Apple App Review 3.1.3): the native app must
 * not sell digital goods outside in-app purchase, nor link to where they are sold.
 * Inside the app (UA "JuwriApp/") every plan / add-on / credit / paid-app
 * purchase page and action is replaced by a neutral notice with NO link or URL.
 * Owners keep full access to everything they already bought on the web. The
 * browser and the PWA are unaffected.
 *
 * P5-17 (owner decision 2026-10-08): the app now sells plans through Apple / Google in-app purchase. Only the app's own
 * billing endpoints (owner-app/billing/*, verified store purchases) are open to it; every web checkout surface above
 * stays blocked inside the app, so nothing digital is sold there outside in-app purchase.
 */
class BlockInAppPurchases
{
    /** Purchase surfaces by route URI (method-agnostic: pages and their POSTs). */
    public const URIS = [
        'subscription',
        'pricing',
        'checkout',
        'checkout/otp/send',
        'checkout/otp/verify',
        'addons',
        'addons/checkout',
        'wallet/topup',
        'studio/pricing',
        'studio/checkout',
        'payment/{transaction}',
        'user/store/apps/{appId}/buy-card',
        'subscription/renew-now',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! OwnerApp::inApp($request)) {
            return $next($request);
        }

        $uri = optional($request->route())->uri();
        if (! is_string($uri) || ! in_array($uri, self::URIS, true)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            return response()->json([
                'message' => ___('Purchases are not available in the app.'),
                'code' => 'purchase_unavailable_in_app',
            ], 403);
        }

        return response()->view('owner.owner-app.purchase-unavailable', [], 403);
    }
}
