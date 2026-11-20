<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use App\Services\SitemapService;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Contracts\Marketplace\Directory;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\SitemapHostCache;

/**
 * rev/seo R6: one dynamic sitemap index replaces the three conflicting
 * artifacts (inline closure route, cron-written static file, stale root file).
 * Sections cached — the restaurants query walks every post. Every <loc> is built from the request host, so a section
 * is cached per host (SitemapHostCache): the platform host keeps the plain key, any other host its own.
 */
class SitemapController extends Controller
{
    private const TTL = 21600; // 6h

    public function index()
    {
        // P6-307: a store on its own address serves THAT store's sitemap (home + product pages), never the platform's.
        $storeId = (int) request()->attributes->get(HostMap::STORE_ATTRIBUTE, 0);
        if ($storeId > 0) {
            $store = Store::query()->findOrFail($storeId);

            return $this->xml(SitemapService::buildXml(SitemapService::storeEntries($store)));
        }

        // RESELLER-PAGES R6: on a reseller domain, serve THAT reseller's sitemap only (cached per host/generation).
        $reseller = app(ResellerContext::class)->forRequest();
        if ($reseller) {
            return $this->xml(Cache::flexible(
                SitemapHostCache::key('sitemap.reseller.'.$reseller->getKey()),
                [self::TTL, self::TTL * 2],
                fn () => SitemapService::buildXml(app(ResellerSite::class)->sitemapEntries($reseller))
            ));
        }

        return $this->xml(SitemapService::buildIndexXml(array_values(array_filter([
            route('sitemap.marketing'),
            route('sitemap.blog'),
            ...$this->restaurantSitemapUrls(),
            route('sitemap.fusion'),
            // Omitted while the module is dark — pointing crawlers at a
            // sitemap that 404s is worse than not listing it at all.
            app(Directory::class)->enabled() ? route('sitemap.directory') : null,
        ]))));
    }

    public function marketing()
    {
        $this->abortOnResellerHost();

        return $this->xml(Cache::flexible(SitemapHostCache::key('sitemap.marketing'), [self::TTL, self::TTL * 2], fn () => SitemapService::buildXml(SitemapService::marketingEntries())));
    }

    public function blog()
    {
        $this->abortOnResellerHost();

        return $this->xml(Cache::flexible(SitemapHostCache::key('sitemap.blog'), [self::TTL, self::TTL * 2], fn () => SitemapService::buildXml(SitemapService::blogEntries())));
    }

    public function stores(int $page = 1)
    {
        $this->abortOnResellerHost();
        abort_if($page < 1 || $page > $this->restaurantPages(), 404);

        return $this->xml(Cache::flexible(SitemapHostCache::key('sitemap.restaurants.'.$page), [self::TTL, self::TTL * 2], fn () => SitemapService::buildXml(SitemapService::restaurantEntries($page))));
    }

    /** P6-306: files needed at 40k stores each (cached with the sections). */
    private function restaurantPages(): int
    {
        return (int) Cache::flexible('sitemap.restaurants.pages', [self::TTL, self::TTL * 2], fn () => SitemapService::restaurantPageCount());
    }

    /** @return list<string> */
    private function restaurantSitemapUrls(): array
    {
        $pages = $this->restaurantPages();
        if ($pages <= 1) {
            return [route('sitemap.restaurants')];
        }

        return array_map(fn (int $n) => route('sitemap.restaurants.page', ['page' => $n]), range(1, $pages));
    }

    /** T6-6: Fusion marketplace pages. */
    public function fusion()
    {
        // FR-37: the sitemap route is outside the mk.context group, so resolve the
        // tenant from the host here — otherwise mkApproved() defaults to platform and a
        // reseller host serves the platform store list.
        $tenant = app(ResellerContext::class)->forRequest();
        if ($tenant) {
            app()->instance('mk.tenant', $tenant);
        } else {
            app()->forgetInstance('mk.tenant');
        }

        // FR-33: key the cache on the tenant (0 = platform) — otherwise the first host
        // to warm it serves its store list to every other Fusion host. fusionEntries()
        // is tenant-fenced via mkApproved().
        $key = SitemapHostCache::key('sitemap.fusion:'.mk_tenant_id());

        return $this->xml(Cache::flexible($key, [self::TTL, self::TTL * 2], fn () => SitemapService::buildXml(SitemapService::fusionEntries())));
    }

    /** DIRECTORY L1 T1-4: explore city/cuisine pages + indexable unclaimed listings. */
    public function directory()
    {
        $this->abortOnResellerHost();
        // SitemapService::directoryEntries() checks the gate, but the CACHE
        // outlives the switch: a file built while the module was on kept
        // serving URLs that 404 after it was turned off. Refuse at the route
        // and key the cache on the gate state so neither half can go stale.
        abort_unless(app(Directory::class)->enabled(), 404);

        return $this->xml(Cache::flexible(SitemapHostCache::key('sitemap.directory.on'), [self::TTL, self::TTL * 2], fn () => SitemapService::buildXml(app(Directory::class)->sitemapEntries())));
    }

    private function abortOnResellerHost(): void
    {
        abort_if(app(ResellerContext::class)->forRequest() !== null, 404);
    }

    private function xml(string $body)
    {
        return response($body, 200)->header('Content-Type', 'application/xml');
    }
}
