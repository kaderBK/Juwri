<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Support\MarketingPricingCache;
use Juwri\Modules\Content\Http\Middleware\CacheMarketingResponse;
use Tests\TestCase;

/**
 * BL-036: saving or deleting a plan must re-key the cached /pricing page.
 *
 * The marketing full-page cache stores a rendered pricing page for 12 hours, so
 * a plan edit (a new price, a renamed plan, a plan deleted) stayed invisible
 * until the TTL expired. The catalogue stamp
 * ({@see MarketingPricingCache}) is folded into the cache key of the pricing
 * pages: a bump orphans the cached body and the next request renders the
 * current catalogue.
 */
class MarketingPricingCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(MarketingPricingCache::KEY);
    }

    public function test_the_stamp_starts_at_one_and_a_bump_increases_it(): void
    {
        $this->assertGreaterThanOrEqual(1, MarketingPricingCache::version());

        $before = MarketingPricingCache::version();
        MarketingPricingCache::bump();

        $this->assertGreaterThan($before, MarketingPricingCache::version());
    }

    public function test_the_stamp_survives_a_bump_without_a_cache_store(): void
    {
        // A cache store that throws must not break a plan save: the stamp stays
        // readable (the 12h TTL of the marketing cache is the fallback).
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('store down'));
        Cache::shouldReceive('forever')->andThrow(new \RuntimeException('store down'));

        $this->assertSame(1, MarketingPricingCache::version());

        MarketingPricingCache::bump();

        $this->assertSame(1, MarketingPricingCache::version());
    }

    public function test_the_pricing_cache_key_changes_when_the_stamp_is_bumped(): void
    {
        $request = $this->requestForRoute('pricing');

        $before = $this->cacheKey($request);
        MarketingPricingCache::bump();
        $after = $this->cacheKey($request);

        $this->assertNotSame($before, $after, 'the marketing cache key did not change after the stamp was bumped');
    }

    public function test_the_stamp_only_re_keys_the_pricing_pages(): void
    {
        $request = $this->requestForRoute('faqs');

        $before = $this->cacheKey($request);
        MarketingPricingCache::bump();
        $after = $this->cacheKey($request);

        $this->assertSame($before, $after, 'a page that shows no plan must not be re-keyed');
    }

    /** A request whose route carries the given name (the middleware keys on the route name). */
    private function requestForRoute(string $name): Request
    {
        $request = Request::create('/'.$name, 'GET');
        $route = new Route(['GET'], $name, fn () => null);
        $route->name($name);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /** The middleware's private cache key for a request (bound, not reflected). */
    private function cacheKey(Request $request): string
    {
        $reader = Closure::bind(
            fn (Request $request): string => $this->key($request),
            new CacheMarketingResponse,
            CacheMarketingResponse::class
        );

        return $reader($request);
    }
}
