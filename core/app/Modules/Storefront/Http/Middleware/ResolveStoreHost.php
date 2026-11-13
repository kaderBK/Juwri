<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StorePath;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-20 (J28): the ONE inbound store resolver, global (before routing). Replaces ResolveCustomDomain, which
 * only served `/` on a custom domain.
 *
 * On a verified store host (custom domain, its `www.` alias, or a platform subdomain row) the request is
 * marked with the store id ({@see HostMap::STORE_ATTRIBUTE}) and its path is mapped onto the path-form
 * storefront routes: `https://menu.example.com/{rest}` is routed as `/{slug}/{rest}` (locale prefix kept),
 * so EVERY storefront route works under every address form. The rewrite happens only when the prefixed path
 * matches a route whose first segment is `{slug}` bound to this store — anything else (customer JSON under
 * `restaurant/{id}/…`, assets, API) is routed unchanged.
 *
 * Unverified custom domains never resolve (takeover guard). In split mode an unknown host, or a storefront
 * subdomain without a verified row, is a 404 — it never falls through to platform pages.
 */
class ResolveStoreHost
{
    public function __construct(private StoreAddressResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = StoreDomain::normalizeHost($request->getHost());
        if ($host === '' || HostMap::kind($host) !== HostMap::UNKNOWN) {
            return $next($request);
        }

        $row = $this->resolver->domainForHost($host);
        $store = $row ? Store::query()->find($row->store_id, ['id', 'slug']) : null;
        if (! $store || (string) $store->slug === '') {
            // A name under a module-registered extra storefront host that no resolver claims is never the app,
            // in either mode (it would otherwise serve every platform page under a foreign-looking host).
            if (HostMap::split() || HostMap::isUnderStorefrontAlias($host)) {
                abort(404);
            }

            return $next($request);
        }

        $request->attributes->set(HostMap::STORE_ATTRIBUTE, (int) $store->id);

        return $next($this->rewrite($request, (string) $store->slug) ?? $request);
    }

    /** The request re-pathed to `/{locale?}/{slug}/{rest}`, or null when the path is not a store page. */
    private function rewrite(Request $request, string $slug): ?Request
    {
        ['locale' => $locale, 'segments' => $segments] = StorePath::split($request->getPathInfo());
        if (rawurldecode($segments[0] ?? '') === $slug) {
            return null; // already store-relative (links built with route('…', $slug) on this host)
        }
        $path = '/'.implode('/', array_filter([$locale, rawurlencode($slug), ...$segments], fn ($p) => $p !== null && $p !== ''));
        $candidate = self::withPath($request, $path);

        try {
            $route = app('router')->getRoutes()->match($candidate);
        } catch (\Throwable) {
            return null;
        }

        return self::isStoreRoute($route, $slug) ? $candidate : null;
    }

    /** A route whose first path segment (after an optional locale prefix) is `{slug}`, bound to $slug. */
    public static function isStoreRoute(Route $route, string $slug): bool
    {
        if ($route->parameter('slug') !== $slug) {
            return false;
        }
        $uri = ltrim($route->uri(), '/');
        $first = explode('/', $uri)[0];
        if ($first !== '{slug}') {
            $parts = explode('/', $uri);
            $first = (count($parts) > 1 && preg_match('/^[a-z]{2}(?:[-_][A-Za-z]{2,4})?$/', $parts[0])) ? $parts[1] : $first;
        }

        return $first === '{slug}';
    }

    private static function withPath(Request $request, string $path): Request
    {
        $server = $request->server->all();
        $query = (string) $request->server->get('QUERY_STRING', '');
        $server['REQUEST_URI'] = $path.($query !== '' ? '?'.$query : '');

        return $request->duplicate(null, null, null, null, null, $server);
    }
}
