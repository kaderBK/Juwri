<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Http\Cache\FullPageCacheKey;
use Juwri\Core\Support\MarketingPricingCache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-page cache for anonymous marketing pages — cuts TTFB from ~1s+ (PHP render
 * + the per-request marquee/nav/thumbnail queries) to a cache read. The public
 * marketing pages are identical for every anonymous visitor in a given host+locale
 * and carry no per-user content, so the rendered HTML is safe to reuse.
 *
 * Safety:
 *  - GET + guest only (a logged-in user or any POST bypasses it).
 *  - Route allowlist — only pure content pages, never the dashboard/storefront/api.
 *  - The body is stored ONLY when it contains no CSRF token, so any page with a
 *    form (contact, blog comment, partner signup, feedback) is never cached — a
 *    stale per-session token would break its form.
 *  - Theme (light/dark) is applied client-side from localStorage, so the HTML is
 *    theme-agnostic and the cache is theme-safe.
 *  - Key includes scheme+host+port (reseller multi-tenant; proxy headers), locale, and the URI minus tracking tags.
 *  - /pricing and home also carry the plan-catalogue stamp (MarketingPricingCache):
 *    saving or deleting a plan orphans the cached body, so the new catalogue shows
 *    at once instead of after the 12h TTL.
 *  - 12h TTL as a backstop; `php artisan cache:clear` on deploy drops it so a new
 *    release (and its bumped asset ?v) is served immediately.
 *  - `?nocache=1` bypasses, for debugging.
 */
class CacheMarketingResponse
{
    private const TTL_HOURS = 12;

    /**
     * Bump to orphan every cached page on deploy when markup-level SEO changes
     * (canonical / hreflang) must go live at once — prod `cache:clear` can fail on
     * file permissions, and stale entries would otherwise live up to TTL_HOURS.
     * 5: the pricing stamp (MarketingPricingCache::version()) is part of the key,
     * so saving or deleting a plan orphans the cached /pricing and home bodies
     * (they showed the old catalogue until the 12h TTL expired).
     */
    private const KEY_VERSION = 5; // 3: bodies store a nonce placeholder (CSP on marketing pages); 4: origin (scheme+port) keyed (P6-79); 5: pricing stamp keyed (BL-036)

    /**
     * Route names safe to full-page cache (no per-user content).
     *
     * @var list<string>
     */
    private array $routes = [
        'home', 'faqs', 'features', 'themes', 'whatsapp.landing', 'no-commission',
        'pricing', 'testimonials', 'studio', 'marketplace.index', 'marketplace.show',
        'partners.index', 'developers', 'developers.apps', 'developers.themes',
        'mcp.landing', 'compare.show', 'country.landing', 'guides.show',
        'tools.commission-calculator', 'tools.reseller-calculator', 'tools.qr-generator',
    ];

    /** Stands in for the CSP nonce in a stored page (never a valid nonce: it has characters a nonce never has). */
    public const NONCE_PLACEHOLDER = '%%csp-nonce%%';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cacheable($request)) {
            return $next($request);
        }

        // P6-79: tracking tags are dropped before render and key (one entry per page, not per campaign link)
        FullPageCacheKey::stripTrackingParams($request);
        $key = $this->key($request);

        $hit = Cache::get($key);
        if (is_array($hit) && isset($hit['body'])) {
            // the page's CSP nonce is per response: the stored body carries a placeholder, filled with THIS request's
            // nonce (the one the outer csp.dashboard middleware puts in the header)
            return response(str_replace(self::NONCE_PLACEHOLDER, csp_nonce(), (string) $hit['body']), 200)
                ->header('Content-Type', $hit['ct'])
                ->header('X-Marketing-Cache', 'HIT');
        }

        $response = $next($request);

        if ($this->storeable($response)) {
            $body = (string) $response->getContent();
            if ($this->safeToStore($body)) {
                $nonce = csp_nonce();
                Cache::put($key, [
                    'body' => $nonce !== '' ? str_replace('nonce="'.$nonce.'"', 'nonce="'.self::NONCE_PLACEHOLDER.'"', $body) : $body,
                    'ct' => $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                ], now()->addHours(self::TTL_HOURS));
                $response->headers->set('X-Marketing-Cache', 'MISS');
            } else {
                $response->headers->set('X-Marketing-Cache', 'BYPASS');
            }
        }

        return $response;
    }

    private function cacheable(Request $request): bool
    {
        // Never full-page-cache under the test runner: each test sets up its own
        // state and asserts on the rendered HTML, so a shared cache would serve one
        // test's page to another (array cache persists across tests in a worker).
        return ! $this->disabledForTesting()
            && $request->isMethod('GET')
            && ! $request->boolean('nocache')
            && Auth::guest()
            && $request->route() !== null
            && $request->routeIs(...$this->routes);
    }

    private function storeable(Response $response): bool
    {
        return $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            // A per-request csp_nonce() is baked into the HTML's inline scripts. If a
            // CSP header (enforced OR report-only) is ever emitted here, its fresh
            // nonce would not match the cached HTML's stale one. Skip caching then, so
            // enabling CSP can never silently break the marketing pages.
            && ! $response->headers->has('Content-Security-Policy')
            && ! $response->headers->has('Content-Security-Policy-Report-Only');
    }

    /** Never cache a page carrying a CSRF token / form — the token is per-session. */
    private function safeToStore(string $body): bool
    {
        return stripos($body, 'name="_token"') === false
            && stripos($body, 'csrf-token') === false
            && stripos($body, 'csrf_token') === false;
    }

    private function key(Request $request): string
    {
        return 'mpc'.self::KEY_VERSION.':'.sha1(implode('|', [
            // P6-79: scheme + host + port (a trusted proxy's X-Forwarded-Proto/-Port change every absolute URL)
            FullPageCacheKey::origin($request),
            app()->getLocale(),
            FullPageCacheKey::uri($request),
            // BL-036: the pricing pages show the plan catalogue — a saved or
            // deleted plan bumps the stamp, which orphans the cached body.
            $request->routeIs('pricing', 'home') ? 'pf'.MarketingPricingCache::version() : '',
        ]));
    }

    /** Overridden by the cache's own feature tests (the shared `array` store would bleed pages between tests). */
    protected function disabledForTesting(): bool
    {
        return app()->runningUnitTests();
    }
}
