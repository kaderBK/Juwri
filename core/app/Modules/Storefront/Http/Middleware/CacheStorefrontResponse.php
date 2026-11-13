<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use App\Services\Storefront\StorefrontMenuCache;
use App\Support\KioskContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Http\Cache\FullPageCacheKey;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-page cache for the ANONYMOUS storefront menu page (route `publicView`).
 *
 * WHY: the assembled menu DB load is ~10ms, but the full response — framework boot,
 * the whole publicView controller, a heavy theme blade, and shared-host PHP overhead —
 * is ~1.4s TTFB on prod. That TTFB alone drags LCP over the Core Web Vitals threshold
 * on every store (CrUX shows 0 "good" URLs). The rendered HTML is identical for every
 * guest of a given store in a given locale + branch — display currency and light/dark
 * theme are applied client-side over the base HTML — so it is safe to reuse.
 *
 * The two things the marketing cache refuses to touch (CSP + CSRF) are handled here:
 *  - CSP: the storefront emits `Content-Security-Policy-Report-Only` with a per-request
 *    nonce. AddCspHeaders is route-level, i.e. INNER to this group middleware, so on a
 *    MISS we capture that header (with its nonce) and store it beside the body; on a HIT
 *    we replay the cached header so its nonce matches the cached body's. (Report-only
 *    means drift would only file a CSP report, never break the page — we keep them in
 *    lockstep regardless.)
 *  - CSRF: the per-session `csrf_token()` is replaced by a fixed sentinel before the
 *    body is stored, and re-injected fresh on every HIT. `StartSession` is global and
 *    OUTER to this middleware, so it still runs on a hit and sets the matching
 *    XSRF/session cookie — the order form's token stays valid.
 *
 * KEY: scheme+host+port | locale | menu-language cookie | geo hint | slug | ?branch_id | the store's menu-cache token
 * (`StorefrontMenuCache::version`). Any menu / category / branch / store-settings /
 * theme write bumps that token (settings + theme wired to bust in this change), so the
 * page invalidates automatically on any cache driver — no tags, no key enumeration.
 *
 * Rollout: `config('perf.storefront_fullpage_cache')` master switch (default OFF) plus
 * an optional slug allowlist (`...canary`) so a handful of stores can be enabled first.
 * `?nocache=1` and the tracking/preview/table params bypass it.
 */
class CacheStorefrontResponse
{
    /** Placeholder swapped in for the per-session CSRF token while cached. */
    private const SENTINEL = '@@SF_CSRF@@';

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->cacheableSlug($request);
        if ($slug === null) {
            return $next($request);
        }

        $storeId = Store::where('slug', $slug)->value('id');
        if (! $storeId) {
            return $next($request); // unknown slug — let the controller 404 it
        }

        // master switch (all stores, optional canary list) OR the owner's per-store trial switch (TTL 300 s)
        $trial = StoreSettings::bool((int) $storeId, 'storefront_fullpage_cache_trial', false);
        $master = (bool) config('perf.storefront_fullpage_cache') && $this->inCanary($slug);
        if (! $master && ! $trial) {
            return $next($request);
        }
        $ttl = $master ? $this->ttl() : max(60, (int) config('perf.storefront_fullpage_cache_trial_ttl', 300));

        // P6-79: tracking tags never reach the render (fullUrlWithQuery links would copy them into every guest's page)
        FullPageCacheKey::stripTrackingParams($request);
        $key = $this->key($request, $slug, (int) $storeId);

        $hit = Cache::get($key);
        if (is_array($hit) && isset($hit['body'])) {
            $body = str_replace(self::SENTINEL, csrf_token(), (string) $hit['body']);
            $resp = response($body, 200)
                ->header('Content-Type', (string) $hit['ct'])
                ->header('Vary', 'Cookie')
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Storefront-Cache', 'HIT');
            if (! empty($hit['csp'])) {
                $resp->headers->set('Content-Security-Policy-Report-Only', (string) $hit['csp']);
            }
            // A2: an enforced theme's header is replayed too (nonce matches the cached body).
            if (! empty($hit['csp_enforce'])) {
                $resp->headers->set('Content-Security-Policy', (string) $hit['csp_enforce']);
            }

            return $resp;
        }

        $response = $next($request);

        if ($this->storeable($response)) {
            $body = (string) $response->getContent();
            $token = csrf_token();
            if ($token !== '' && str_contains($body, $token)) {
                $body = str_replace($token, self::SENTINEL, $body);
            }
            Cache::put($key, [
                'body' => $body,
                'ct' => $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                'csp' => (string) $response->headers->get('Content-Security-Policy-Report-Only', ''),
                'csp_enforce' => (string) $response->headers->get('Content-Security-Policy', ''),
            ], now()->addSeconds($ttl));
            $response->headers->set('X-Storefront-Cache', 'MISS');
        }

        return $response;
    }

    /** The cacheable slug for this request, or null when it must not be cached. */
    private function cacheableSlug(Request $request): ?string
    {
        if ($this->disabledForTesting()
            || ! $request->isMethod('GET')
            || $request->boolean('nocache')
            || $request->route() === null
            || ! $request->routeIs('publicView')
            // Tracking / preview / dine-in params personalise the render.
            || $request->hasAny(['qr-id', 'table', 'rt', 'preview_template', 'preview_theme', 'preview_lang'])
            // P4-16: any other query changes the body (`?fragment=item|cat|grid` answers a piece of the page, `?item=` opens
            // an item, `?q= ?filter ?sort ?cursor ?category ?offset ?variant` list or expand the catalogue): only the
            // branch and campaign tags leave it the same page for every guest. P6-79: `?lang=` is NOT one of them — the
            // controller switches the menu language from it (after this key is built) and queues the remembered cookie,
            // so it bypasses; the follow-up visits carry that cookie, which is keyed (key()).
            || array_diff(array_keys($request->query()), ['branch_id', ...FullPageCacheKey::TRACKING_PARAMS]) !== []
            // a non-numeric / non-canonical branch_id would be keyed as its int but echoed raw by the branch links
            || ($request->query->has('branch_id') && ! (is_string($bid = $request->query->all()['branch_id'] ?? null) && preg_match('/^[1-9][0-9]{0,9}$/', $bid)))
            // A session flash message (e.g. an error banner from a redirect) is
            // per-visitor — never freeze it into a shared page.
            || ! empty(session()->get('_flash.old'))
            // Guests only: the web guard AND the customer guard (a signed-in diner
            // sees their name / addresses / wallet inline).
            || ! Auth::guest()
            || auth('customer')->check()
            // P3-06: a kiosk browser session is served the kiosk layout and must never fill (or read) the guests' cache
            || KioskContext::stores() !== []) {
            return null;
        }

        $slug = (string) $request->route('slug');
        if ($slug === '') {
            return null;
        }

        return $slug;
    }

    /** The canary list (perf.storefront_fullpage_cache_canary) limits the MASTER switch; empty = every store. */
    private function inCanary(string $slug): bool
    {
        $canary = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('perf.storefront_fullpage_cache_canary', ''))
        )));

        return $canary === [] || in_array($slug, $canary, true);
    }

    private function storeable(Response $response): bool
    {
        return $response->getStatusCode() === 200
            && ! $response->isRedirection()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function key(Request $request, string $slug, int $storeId): string
    {
        return 'sfpc2:'.sha1(implode('|', [
            // P6-79: scheme + host + port, not the host alone (X-Forwarded-Proto/-Port via a trusted proxy change every absolute URL)
            FullPageCacheKey::origin($request),
            app()->getLocale(),
            // the menu language the controller resolves from the remembered plain cookie (non-prefix locale mode)
            $this->menuLanguageCookie(),
            // AL-40: the geo hint is printed into the page when the owner provisioned a geo source (null = dark)
            function_exists('visitor_country') ? (string) visitor_country() : '',
            $slug,
            (int) $request->query('branch_id'),
            StorefrontMenuCache::version($storeId),
        ]));
    }

    /** The remembered menu-language cookie StoreController::storefrontLanguage reads, normalised to a language-code shape. */
    private function menuLanguageCookie(): string
    {
        $code = $_COOKIE['Quick_user_lang_code'] ?? request()->cookies->get('Quick_user_lang_code');

        return is_string($code) && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $code) ? $code : '';
    }

    private function ttl(): int
    {
        return max(60, (int) config('perf.storefront_fullpage_cache_ttl', 3600));
    }

    /**
     * Never cache under the test runner: the `array` cache store persists across a
     * paratest worker's tests and would serve one test's store to another. The
     * middleware's own feature test overrides this to exercise the cache path.
     */
    protected function disabledForTesting(): bool
    {
        return app()->runningUnitTests();
    }
}
