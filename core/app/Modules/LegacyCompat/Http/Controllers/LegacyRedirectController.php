<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Store;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyCustomerRoutes;

/**
 * J52b: legacy GET forms that answer with a 301 (LEGACYCOMPAT-SPEC §3, §4). Every action 404s while the module is
 * off, even if a cached route table still lists it (security review S3).
 */
class LegacyRedirectController extends Controller
{
    /**
     * /restaurant/{slug}/{slugTitle?} → 301 /{slug} with the query kept, `public, max-age=86400` (spec §3 "Redirect
     * rules"). The segment is matched like the storefront page does (numeric = store id first, else the slug,
     * byte-for-byte) and kept as given in the target; unknown → 404. Nothing is added to the URL.
     */
    public function storePage(Request $request, string $slug, ?string $slugTitle = null): RedirectResponse
    {
        abort_unless(LegacyCompatRoutes::enabled(), 404);

        $exists = (ctype_digit($slug) && Store::query()->whereKey((int) $slug)->exists())
            || Store::query()->where('slug', $slug)->exists();
        abort_unless($exists, 404);

        $qs = http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986);
        $response = redirect()->to(url('/'.rawurlencode($slug)).($qs !== '' ? '?'.$qs : ''), 301);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    /**
     * Old signed-in customer GET paths `/restaurant/{id}/…` (wallet balance, top-up page, loyalty balance, my-orders,
     * notifications) → 301 to the core route's current URL, query kept, `public, max-age=86400`. Only routed once core
     * no longer answers the old path itself ({@see LegacyCustomerRoutes}). No lookup and no auth here: the target
     * applies its own auth:customer guard and store checks; nothing is added to the URL.
     */
    public function customerPath(Request $request): RedirectResponse
    {
        abort_unless(LegacyCompatRoutes::enabled(), 404);

        $route = $request->route();
        if (! $route instanceof RoutingRoute) {
            abort(404);
        }
        $target = $route->getAction(LegacyCustomerRoutes::TARGET_ACTION);
        if (! is_string($target) || $target === '') {
            abort(404);
        }

        $values = array_map(static fn (mixed $v): string => (string) $v, array_values($route->parameters()));
        $url = LegacyCustomerRoutes::targetUrl(Route::getRoutes(), $target, $values, $request);
        if ($url === null) {
            abort(404);
        }

        $response = redirect()->to($url, 301);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
