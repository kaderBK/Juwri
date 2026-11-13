<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use Juwri\Core\Models\Store;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StorePath;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-20 (J28): one primary address per store — a GET/HEAD storefront page reached on any other address form
 * (path form while a domain is primary, a secondary domain, the `www.` alias, a platform subdomain, or — in
 * split mode — the path form on a non-storefront host) answers 301 to the same page on the primary address,
 * path + query kept.
 *
 * Open-redirect safe: the target host is only ever the store's verified primary row or the configured
 * storefront base; the request only contributes store-relative path segments (empty segments dropped, so
 * `//evil.example` cannot become a scheme-relative URL) and its query string.
 */
class RedirectToPrimaryStoreAddress
{
    /** GET storefront routes that stay on the address they were asked on. */
    private const STAY = ['publicView.manifest'];

    public function __construct(private StoreAddressResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('hosts.redirect_to_primary', true) || ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }
        $route = $request->route();
        $slug = $route?->parameter('slug');
        if (! $route || ! is_string($slug) || $slug === '' || in_array($route->getName(), self::STAY, true)
            || $request->expectsJson() || $this->dashboardViewer($request)) {
            return $next($request);
        }
        $oldUrl = str_starts_with(ltrim($route->uri(), '/'), 'restaurant/{slug}');
        if (! $oldUrl && ! ResolveStoreHost::isStoreRoute($route, $slug)) {
            return $next($request);
        }
        $store = Store::query()->where('slug', $slug)->first(['id', 'slug']);
        if (! $store) {
            return $next($request);
        }

        if ($this->onPrimary($request, (int) $store->id)) {
            return $next($request);
        }

        ['locale' => $locale, 'rest' => $rest] = StorePath::rest($request->getPathInfo(), $slug);
        $target = $this->resolver->localized((int) $store->id, $slug, $locale, $rest);
        $query = $request->getQueryString();
        if ($query) {
            $target .= (parse_url($target, PHP_URL_PATH) ?? '') === '' ? '/?'.$query : '?'.$query;
        }

        return new RedirectResponse($target, 301);
    }

    /**
     * A signed-in dashboard user (owner/staff/admin, web guard) previews the store where they are — Theme
     * Studio drafts, ?preview_template and the dashboard's own store links need that session, which the
     * store's other origins never carry.
     */
    private function dashboardViewer(Request $request): bool
    {
        try {
            return $request->hasSession() && auth('web')->check();
        } catch (\Throwable) {
            return false;
        }
    }

    private function onPrimary(Request $request, int $storeId): bool
    {
        $host = StoreDomain::normalizeHost($request->getHost());
        $primary = $this->resolver->primaryHost($storeId);
        $onStoreHost = (int) $request->attributes->get(HostMap::STORE_ATTRIBUTE) === $storeId;

        if ($primary !== null) {
            return $onStoreHost && $host === $primary;
        }
        if ($onStoreHost) {
            return false; // a domain/subdomain row that is not primary → the path form is
        }

        // Path form is primary: in split mode it lives on the storefront host only.
        return ! HostMap::split() || $host === HostMap::storefrontHost();
    }
}
