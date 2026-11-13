<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\App;
use App\Models\SitePage;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Content\PlatformSite;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Services\CompareDefs;
use Juwri\Modules\Content\Services\GuideDefs;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Juwri\Modules\Content\Support\ContentLocales;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Verticals\Services\VerticalLandingDefs;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * P9-6a Option 2b: build the public sitemap ourselves from known URLs instead
 * of crawling the site (dropped spatie crawler/browsershot chain).
 *
 * rev/seo R6: evolved from a single static file into the builder behind the
 * dynamic sitemap INDEX (/sitemap.xml → marketing / blog / restaurants). In
 * URL-prefix locale mode the marketing section lists every locale variant,
 * each carrying the full xhtml:link alternate set; restaurants carry the
 * alternate set on their canonical entry (3,200+ pages — listing all 11
 * variants per restaurant would balloon the file for no discovery gain, the
 * on-page hreflang covers the rest).
 */
class SitemapService
{
    /** @return array<int, array{loc: string, alternates?: array<string,string>, priority?: string}> */
    public static function marketingEntries(): array
    {
        // Localized pages (live in the locale-prefix route groups): each gets a
        // per-locale <url> with the full xhtml:link alternate set in prefix mode.
        $localized = [
            'home' => '1.0', 'studio' => '0.9', 'features' => '0.8', 'pricing' => '0.7',
            'faqs' => '0.7', 'testimonials' => '0.6', 'contact' => '0.6', 'blog.index' => '0.7',
            // SEO-growth: the new marketing surfaces (all locale-prefixed).
            'themes' => '0.7', 'whatsapp.landing' => '0.8', 'no-commission' => '0.8',
            'tools.commission-calculator' => '0.6', 'tools.qr-generator' => '0.6', 'tools.menu-scanner' => '0.6',
            'tools.reseller-calculator' => '0.6', 'partners.index' => '0.7',
        ];

        // Standalone pages (single-language, registered outside the locale
        // groups — /marketplace, /apps/store, /developers* have no /en/ variant,
        // so emit ONE unprefixed URL with no alternates; a locale variant would 404.
        $standalone = ['marketplace.index' => '0.7', 'apps.store.index' => '0.7', 'studio.templates' => '0.6', 'developers' => '0.5', 'developers.apps' => '0.5', 'developers.themes' => '0.5'];

        $pitch = [
            'features', 'pricing', 'testimonials', 'themes', 'whatsapp.landing', 'no-commission',
            'tools.commission-calculator', 'tools.qr-generator', 'tools.menu-scanner', 'tools.reseller-calculator',
        ];
        $pitchOn = app(PlatformSite::class)->enabled();

        $entries = [];
        foreach ($localized as $name => $priority) {
            if (! Route::has($name) || (in_array($name, $pitch, true) && ! $pitchOn)) {
                continue;
            }
            $url = rtrim(route($name), '/');

            if (! config('settings.include_language_code')) {
                $entries[] = ['loc' => $url, 'priority' => $priority];

                continue;
            }

            $alternates = static::alternatesFor($url);
            foreach ($alternates as $locUrl) {
                $entries[] = ['loc' => $locUrl, 'alternates' => $alternates, 'priority' => $priority];
            }
        }

        foreach ($standalone as $name => $priority) {
            if (Route::has($name)) {
                $entries[] = ['loc' => rtrim(route($name), '/'), 'priority' => $priority];
            }
        }

        // SEO-growth W0.3: dynamic locale-prefixed marketing pages — country
        // landings, competitor comparisons and compliance guides. Enumerate the
        // same whitelists the controllers gate on, so we never list a 404.
        $dynamic = [];
        // Country / compare / guide pages are written in only SOME languages;
        // list just those (ContentLocales) — other prefixes 301 to them.
        if ($pitchOn && Route::has('country.landing')) {
            foreach (CountryDefs::slugs() as $cc) {
                $def = CountryDefs::find($cc);
                $dynamic[] = [rtrim(route('country.landing', $cc), '/'), $def ? ContentLocales::of($def, ['cc', '_default']) : null];
            }
        }
        if ($pitchOn && Route::has('compare.show')) {
            foreach (CompareDefs::slugs() as $slug) {
                $def = CompareDefs::find($slug);
                $dynamic[] = [rtrim(route('compare.show', $slug), '/'), $def ? ContentLocales::of($def) : null];
            }
        }
        if ($pitchOn && Route::has('guides.show')) {
            foreach (GuideDefs::slugs() as $slug) {
                $def = GuideDefs::find($slug);
                $dynamic[] = [rtrim(route('guides.show', $slug), '/'), $def ? ContentLocales::of($def, ['country']) : null];
            }
        }
        // rev/mv-marketing: per-vertical (industry) landing pages, same
        // whitelist the controller gates on (VerticalLandingDefs files).
        if ($pitchOn && Route::has('industries.show')) {
            foreach (VerticalLandingDefs::slugs() as $slug) {
                $dynamic[] = [rtrim(route('industries.show', $slug), '/'), null];
            }
        }
        foreach ($dynamic as [$url, $locales]) {
            if (! config('settings.include_language_code')) {
                $entries[] = ['loc' => $url, 'priority' => '0.6'];

                continue;
            }
            $alternates = static::alternatesFor($url);
            if (is_array($locales) && $locales !== []) {
                $alternates = array_intersect_key($alternates, array_flip($locales));
            }
            foreach ($alternates as $locUrl) {
                $entries[] = ['loc' => $locUrl, 'alternates' => $alternates, 'priority' => '0.6'];
            }
        }

        // Marketplace detail pages (dynamic). Approved apps + published themes
        // that actually have a servable published version — the same gates the
        // public controllers apply, so we never list a URL that 404s.
        if (Route::has('apps.store.show')) {
            foreach (App::where('status', 'approved')->get(['slug', 'updated_at']) as $app) {
                $entries[] = [
                    'loc' => rtrim(route('apps.store.show', $app->slug), '/'),
                    'priority' => '0.6',
                    'lastmod' => optional($app->updated_at)->toDateString(),
                ];
            }
        }
        if (Route::has('marketplace.show')) {
            $themes = Theme::where('status', 'published')
                ->whereHas('versions', fn ($v) => $v->where('status', 'published'))
                ->get(['slug', 'updated_at']);
            foreach ($themes as $theme) {
                $entries[] = [
                    'loc' => rtrim(route('marketplace.show', $theme->slug), '/'),
                    'priority' => '0.6',
                    'lastmod' => optional($theme->updated_at)->toDateString(),
                ];
            }
        }

        // HOME-PAGES HP-52: published Site Pages slug pages ( /page/{slug} ).
        // The home row needs no entry — route('home') is already first above.
        try {
            $site = SiteContext::current();
            if ($site && Route::has('page')) {
                $sitePages = SitePage::forSite($site->id)
                    ->published()->where('type', 'page')->get(['slug', 'updated_at']);
                foreach ($sitePages as $sp) {
                    $entries[] = [
                        'loc' => route('page', $sp->slug),
                        'lastmod' => optional($sp->updated_at)->toDateString(),
                        'priority' => '0.5',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // site_pages table missing — sitemap must never 500.
        }

        return $entries;
    }

    /** @return array<int, array{loc: string, lastmod?: string}> */
    public static function blogEntries(): array
    {
        if (! Route::has('blog.single')) {
            return [];
        }

        $entries = [];
        foreach (Blog::where('status', 'publish')->get(['id', 'slug', 'updated_at']) as $blog) {
            $entries[] = [
                'loc' => route('blog.single', [$blog->id, $blog->slug]),
                'lastmod' => optional($blog->updated_at)->toDateString(),
            ];
        }

        return $entries;
    }

    /** P6-306: URLs per restaurants sitemap file (the protocol allows 50,000; stay clear of it). */
    public const RESTAURANTS_PER_FILE = 40000;

    public static function restaurantsPerFile(): int
    {
        return max(1, (int) config('seo.sitemap_restaurants_per_file', static::RESTAURANTS_PER_FILE));
    }

    /**
     * P6-307: the sitemap a store serves on its own address — the store home plus (goods stores) every indexable product
     * page, all on the store's primary address. A store the platform sitemap would not list has an empty sitemap.
     *
     * @return array<int, array{loc: string, image?: string}>
     */
    public static function storeEntries(Store $store): array
    {
        $store->loadMissing('user');
        $redirected = StoreSettings::string((int) $store->id, 'external_redirect_enabled') === '1';
        if ($redirected || ! ($store->user && (int) $store->user->status === 1 && optional($store->user->plan())->status) || ! store_has_menu((int) $store->id)) {
            return [];
        }
        $resolver = app(StoreAddressResolver::class);
        $base = $resolver->baseUrl($store);
        $entries = [['loc' => $base, 'image' => asset('storage/store/logo/'.$store->main_image)]];
        if (! in_array('ship', (array) config('verticals.types.'.($store->store_type ?? '').'.fulfillment', []), true)) {
            return $entries; // a food item is a sheet of the store page, not a page of its own
        }
        Menu::query()->where('store_id', $store->id)->where('active', '1')
            ->whereNotNull('public_id')->visibleOnStorefront()->orderBy('id')
            ->limit(static::STORE_MAX_URLS - 1)->get(['id', 'public_id', 'image', 'store_id'])
            ->each(function ($item) use (&$entries, $base) {
                $entry = ['loc' => $base.'?item='.$item->public_id];
                if (! empty($item->image) && $item->image !== 'default.png') {
                    $entry['image'] = media_url('storage/menu/', (string) $item->image, (int) $item->store_id);
                }
                $entries[] = $entry;
            });

        return $entries;
    }

    /** P6-307: one store sitemap file holds at most this many URLs (protocol limit 50,000). */
    public const STORE_MAX_URLS = 40000;

    /** Stores that may appear in the sitemap, before the per-owner checks. */
    /** @return Builder<Store> */
    protected static function sitemapStoreQuery(): Builder
    {
        return Store::excludingSandbox()->publiclyListed(); // P6-48
    }

    /** @return int number of restaurants sitemap files needed (at least 1) */
    public static function restaurantPageCount(): int
    {
        return max(1, (int) ceil(count(static::restaurantEntries(null)) / static::restaurantsPerFile()));
    }

    /**
     * @param  int|null  $page  1-based file number; null = every entry
     * @return array<int, array{loc: string, image?: string, alternates?: array<string,string>}>
     */
    public static function restaurantEntries(?int $page = 1): array
    {
        $entries = [];
        // rev/external-redirect A4: a store that has opted out of the platform
        // 302s its `/{slug}` — keep it OUT of the sitemap so crawlers do not chase
        // the redirect forever. One query builds the excluded-id set (no N+1).
        $redirected = collect(StoreSettings::storeIdsWhere('external_redirect_enabled', fn ($v) => $v === '1'))->flip();
        // F5-5: developer sandbox stores are throwaway test data — never index them.
        // SEO Growth (empty-menu): an item-less store renders an empty page that Google flags as a Soft 404 —
        // one query yields every store id that has an active menu item (was: one query per store).
        $withMenu = Menu::where('active', '1')->whereNull('deleted_at')->distinct()->pluck('store_id')->flip();
        $resolver = app(StoreAddressResolver::class);
        $skip = $page === null ? 0 : (max(1, $page) - 1) * static::restaurantsPerFile();
        $take = $page === null ? PHP_INT_MAX : static::restaurantsPerFile();
        $seen = 0;

        static::sitemapStoreQuery()->with('user')->chunkById(1000, function ($stores) use (&$entries, &$seen, $skip, $take, $redirected, $withMenu, $resolver) {
            $resolver->primeBatch($stores->pluck('id')->all());
            $plans = app(PlanCatalog::class)->plansFor($stores->pluck('user')->filter()->unique('id'));
            foreach ($stores as $store) {
                if ($redirected->has($store->id) || ! $withMenu->has($store->id)) {
                    continue;
                }
                if (! ($store->user && (int) $store->user->status === 1 && optional($plans[$store->user->id] ?? null)->status)) {
                    continue;
                }
                if ($seen++ < $skip) {
                    continue;
                }
                if (count($entries) >= $take) {
                    return false;
                }
                // P6-306: the page's canonical is the store's PRIMARY address (custom domain / subdomain), so that is
                // what the sitemap lists; the path form of a store that has a domain is only an alternate page.
                $entry = [
                    'loc' => $resolver->baseUrl($store),
                    'image' => asset('storage/store/logo/'.$store->main_image),
                ];
                if (config('settings.include_language_code')) {
                    // SEO Growth (locale-scoping): only the store's real (translated)
                    // languages, not every platform locale — otherwise we feed Google
                    // ~11 duplicate variants per store.
                    $entry['alternates'] = static::alternatesForStore($store->id, $entry['loc']);
                }
                $entries[] = $entry;
            }
        });

        return $entries;
    }

    /**
     * T6-6 (marketplace): Fusion customer pages — home + every approved store.
     * City browsing is cookie-context (no per-city URLs), so home is one entry.
     */
    public static function fusionEntries(): array
    {
        $entries = [['loc' => url('/fusion')]];
        foreach (Store::mkApproved()->excludingSandbox()->get(['id', 'slug', 'main_image']) as $store) { // F5-5
            $entries[] = [
                'loc' => url('/fusion/store/'.$store->slug),
                'image' => asset('storage/store/logo/'.$store->main_image),
            ];
        }

        return $entries;
    }

    /**
     * Locale-code → URL map for a given URL (prefix mode). Default locale is
     * unprefixed (hideDefaultLocaleInURL), mirroring seo_hreflang_tags().
     *
     * @return array<string, string>
     */
    public static function alternatesFor(string $url): array
    {
        $map = [];
        foreach (array_keys(get_active_languages()) as $code) {
            $localized = LaravelLocalization::getLocalizedURL($code, $url, [], false);
            if ($localized) {
                $map[$code] = rtrim($localized, '/');
            }
        }

        return $map;
    }

    /**
     * Locale-code → URL map for a STORE, scoped to the languages it genuinely
     * publishes (default + real translations) via store_indexable_langs(). A
     * single-language store yields just its default entry, so the sitemap stops
     * advertising phantom / non-offered locale variants of it.
     *
     * @return array<string, string>
     */
    public static function alternatesForStore(int $storeId, string $url): array
    {
        $indexable = store_indexable_langs($storeId);
        if (count($indexable) < 2) {
            return []; // single real language → no hreflang cluster needed
        }
        $map = [];
        foreach ($indexable as $code) {
            $localized = LaravelLocalization::getLocalizedURL($code, $url, [], false);
            if ($localized) {
                $map[$code] = rtrim($localized, '/');
            }
        }

        return $map;
    }

    /** Build a valid sitemap XML document from URL entries (pure — no DB). */
    public static function buildXml(array $entries): string
    {
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">',
        ];

        foreach ($entries as $entry) {
            if (empty($entry['loc'])) {
                continue;
            }
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.$esc($entry['loc']).'</loc>';
            foreach ($entry['alternates'] ?? [] as $code => $href) {
                $lines[] = '    <xhtml:link rel="alternate" hreflang="'.$esc((string) $code).'" href="'.$esc($href).'"/>';
            }
            if (! empty($entry['lastmod'])) {
                $lines[] = '    <lastmod>'.$esc($entry['lastmod']).'</lastmod>';
            }
            if (! empty($entry['priority'])) {
                $lines[] = '    <priority>'.$esc($entry['priority']).'</priority>';
            }
            if (! empty($entry['image'])) {
                $lines[] = '    <image:image><image:loc>'.$esc($entry['image']).'</image:loc></image:image>';
            }
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    /** Build a sitemap index document pointing at the section sitemaps. */
    public static function buildIndexXml(array $sitemapUrls): string
    {
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];
        foreach ($sitemapUrls as $url) {
            $lines[] = '  <sitemap><loc>'.$esc($url).'</loc></sitemap>';
        }
        $lines[] = '</sitemapindex>';

        return implode("\n", $lines)."\n";
    }
}
