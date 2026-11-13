<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\StoreOption;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;

/*
 | SEO / i18n storefront-render helpers, split out of Helper.php to keep that
 | file under its ControllerSizeGuard ceiling. Global-namespace functions,
 | autoloaded via composer "files" exactly like Helper.php — signatures and
 | call sites are unchanged. LaravelLocalization + Throwable resolve through
 | the global facade alias / global namespace, same as in Helper.php.
 */

/**
 * Localized image swap. Given a root-relative asset URL, return the
 * per-locale variant (`name-<lang>.ext`) when that file exists on disk, else
 * the original. Lets a translated screenshot "light up" simply by dropping
 * `app-preview-3-ar.webp` next to `app-preview-3.webp` — no code change. The
 * original is always the safe fallback, so nothing breaks if a variant is
 * missing. Only rewrites root-relative `/assets/...` paths.
 */
function localized_asset(string $path): string
{
    if ($path === '' || $path[0] !== '/' || ! preg_match('/^(.*)(\.[A-Za-z0-9]+)$/', $path, $m)) {
        return $path;
    }

    static $cache = [];
    $lang = get_lang();
    $key = $lang.'|'.$path;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $variant = $m[1].'-'.$lang.$m[2];              // /assets/.../app-preview-3-ar.webp
    $repoRoot = dirname(base_path());               // public assets live at repo root /assets
    $exists = is_file($repoRoot.'/'.ltrim($variant, '/'));

    return $cache[$key] = $exists ? $variant : $path;
}

/**
 * Footer/legal links: resolve a CMS page slug to its ACTIVE, current-locale
 * (or locale-agnostic) Page, or null. Lets the footer render a link only when
 * the page really exists and is published — no dead links, and it follows the
 * visitor's language. Cached per request+locale.
 */
function active_cms_page(string $slug): ?Page
{
    static $cache = [];
    static $boundTo = null;

    // Rebind the memo when the app instance changes (mirrors StoreOption::scopeMemo).
    // Per request in prod the container is stable, so the cache still saves the
    // repeated footer-link lookups; but across tests each RefreshDatabase run gets
    // a fresh app, so a null result cached by one test class can no longer leak into
    // the next (a footer render in one test primed `terms-of-service`→null and broke
    // FooterLinksTest, which then created that page but read the stale null).
    $appId = RequestEpoch::id();
    if ($boundTo !== $appId) {
        $cache = [];
        $boundTo = $appId;
    }

    $key = get_lang().'|'.$slug;

    if (! array_key_exists($key, $cache)) {
        // Mirror HomeController@page: active + current-locale (or locale-agnostic).
        // Prefer the exact-locale row over a null-locale fallback. `type` is a
        // string column ('0' public, '1' login-only) — exclude login-only pages.
        $cache[$key] = Page::where('slug', $slug)
            ->where('active', 1)
            ->whereRaw("COALESCE(type,'0') <> '1'")
            ->where(function ($q) {
                $q->where('translation_lang', get_lang())->orWhereNull('translation_lang');
            })
            ->orderByRaw('CASE WHEN translation_lang = ? THEN 0 ELSE 1 END', [get_lang()])
            ->first();
    }

    return $cache[$key];
}

/**
 * A page title (without the brand suffix) with an install-level override: config('seo.title_overrides.<key>.<locale>').
 * Empty by default, so every install keeps the shipped copy; the old-platform migration fills it so the pages keep the
 * titles search engines already hold.
 */
function seo_title_override(string $key, string $default): string
{
    $override = (config('seo.title_overrides', [])[$key] ?? [])[strtolower((string) get_lang())] ?? null;

    return is_string($override) && $override !== '' ? $override : $default;
}

/**
 * rev/seo R8: short brand for page-title suffixes. The site_title option holds
 * the full home-page marketing title ("<tagline> | <brand>") — appending all of
 * it to every subpage title blows past 60 chars. Take the segment after the
 * last "|" when present, else the full value.
 */
function seo_brand(): string
{
    // P4 (4.7): reseller-aware — a reseller host/merchant gets the reseller brand.
    $ctx = brand_context();
    if ($ctx->isReseller()) {
        return $ctx->shortName();
    }

    $title = config('settings.site_title', config('app.name'));
    if (is_string($title) && str_contains($title, '|')) {
        $parts = explode('|', $title);
        $brand = trim(end($parts));
        if ($brand !== '') {
            return $brand;
        }
    }

    return (string) $title;
}

/**
 * rev/seo R13: og:locale in the xx_XX format Open Graph expects (bare "ar"
 * is invalid) + og:locale:alternate for every other active language.
 */
function seo_og_locale_tags(): string
{
    $langs = get_active_languages();
    $current = get_lang();
    $regional = $langs[$current]['regional'] ?? ($current.'_'.strtoupper($current));

    $tags = ['<meta property="og:locale" content="'.e($regional).'">'];
    foreach ($langs as $code => $meta) {
        if ($code === $current) {
            continue;
        }
        $tags[] = '<meta property="og:locale:alternate" content="'.e($meta['regional'] ?? ($code.'_'.strtoupper($code))).'">';
    }

    return implode("\n    ", $tags);
}

/**
 * JSON for an inline `<script type="application/ld+json">` body. Always hex-
 * escapes < > & ' " so store/owner/customer text (a menu item named
 * `</script><script>…`) can never close the script element or open a comment;
 * slashes and Unicode stay readable. The only encoder for inline JSON-LD —
 * never `{!! json_encode(..., JSON_UNESCAPED_SLASHES) !!}` in a view.
 */
function json_ld(mixed $data): string
{
    return (string) json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
}

/**
 * rev/seo R10: Organization JSON-LD (logo + social profiles). Rendered once
 * per page from the shared layouts.
 */
function seo_organization_jsonld(): string
{
    // DEC-5: a reseller (white-label) host must not advertise the platform's
    // social profiles or logo. seo_brand() is already reseller-aware.
    $reseller = active_brand_reseller();

    $sameAs = $reseller ? [] : array_values(array_filter([
        config('settings.facebook_link'),
        config('settings.twitter_link'),
        config('settings.instagram_link'),
        config('settings.youtube_link'),
    ]));

    $logo = $reseller
        ? brand_logo()
        : (! empty(config('settings.site_logo')) ? asset('storage/logo/'.config('settings.site_logo')) : null);

    $data = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => seo_brand(),
        'url' => url('/'),
        'logo' => $logo,
        'sameAs' => $sameAs ?: null,
    ]);

    return '<script type="application/ld+json">'.json_ld($data).'</script>';
}

/**
 * WebSite JSON-LD for Google's "site names" feature — one node, HOMEPAGE ONLY
 * (root URI). It lets Google use our chosen site name (+ alternates) in the SERP
 * instead of guessing. No SearchAction: the marketing site has no public search
 * results endpoint, so a sitelinks-searchbox action would point nowhere.
 * https://developers.google.com/search/docs/appearance/site-names
 */
function seo_website_jsonld(): string
{
    $name = seo_brand();

    $data = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $name,
        'url' => url('/'),
        'inLanguage' => str_replace('_', '-', app()->getLocale()),
    ]);

    // alternateName: genuine spelling variants of the PLATFORM brand only — a
    // white-label reseller has just its own name, no platform aliases.
    if (! active_brand_reseller()) {
        $alt = 'juwri.com';
        if (strcasecmp($alt, (string) $name) !== 0) {
            $data['alternateName'] = $alt;
        }
    }

    return '<script type="application/ld+json">'.json_ld($data).'</script>';
}

/**
 * rev/seo R10: BreadcrumbList JSON-LD. $items = [['name' => ..., 'url' => ?], ...]
 * in order; the last item usually has no url (current page). JSON_HEX_TAG keeps
 * user-sourced names (blog titles) from breaking out of the script tag.
 *
 * @param  array<int, array{name: string, url?: string}>  $items
 */
function seo_breadcrumbs_jsonld(array $items): string
{
    $list = [];
    foreach (array_values($items) as $i => $item) {
        $entry = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => (string) $item['name']];
        if (! empty($item['url'])) {
            $entry['item'] = $item['url'];
        }
        $list[] = $entry;
    }

    $data = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];

    return '<script type="application/ld+json">'.json_ld($data).'</script>';
}

/**
 * rev/seo R4: full mutual hreflang set for the CURRENT URL — one tag per active
 * language (self-referencing included) + x-default pointing at the default-locale
 * variant. Only meaningful in URL-prefix mode; session mode has a single URL per
 * page, where alternate annotations would lie to crawlers.
 */
function seo_hreflang_tags(): string
{
    if (! config('settings.include_language_code')) {
        return '';
    }

    $tags = [];
    foreach (array_keys(get_active_languages()) as $code) {
        $url = LaravelLocalization::getLocalizedURL($code, null, [], false);
        if (! $url) {
            continue;
        }
        $tags[] = '<link rel="alternate" hreflang="'.e($code).'" href="'.e($url).'" />';
    }

    // NOT config('app.locale') — mcamara mutates that to the CURRENT request
    // locale, which would make x-default drift per page variant.
    $default = LaravelLocalization::getLocalizedURL(LaravelLocalization::getDefaultLocale(), null, [], false);
    if ($default) {
        $tags[] = '<link rel="alternate" hreflang="x-default" href="'.e($default).'" />';
    }

    return implode("\n    ", $tags);
}

/**
 * The language codes a store has ACTUALLY translated its menu into — at least one
 * visible item carries a non-empty title/description for that code. A language a
 * store "offers" (menu_languages) but never translated renders byte-identical to
 * the default page, so only these codes are genuinely distinct, indexable content.
 *
 * SEO Growth (locale-scoping): the storefront + sitemap used to advertise every
 * PLATFORM-active language for every store, so Google crawled ~11 duplicate locale
 * variants per store — the bulk of "Crawled – not indexed" + "Duplicate canonical"
 * in Search Console. Scoping to real languages collapses that to the pages that
 * are actually distinct.
 *
 * @return list<string> lowercase codes, memoized per request per store
 */
function store_translated_langs(int $storeId): array
{
    static $memo = [];
    static $stamp = null;
    $app = RequestEpoch::id(); // per request / app instance: nothing outlives the unit of work (tests, Octane)
    if ($stamp !== $app) {
        $memo = [];
        $stamp = $app;
    }
    if (isset($memo[$app]) && array_key_exists($storeId, $memo[$app])) {
        return $memo[$app][$storeId];
    }

    $langs = [];
    // pluck returns the raw JSON string (bypasses the model's object cast), which
    // is exactly what we want to decode here.
    foreach (Menu::where('store_id', $storeId)->where('active', '1')->whereNull('deleted_at')->pluck('translations') as $raw) {
        $t = is_string($raw) ? json_decode($raw, true) : json_decode(json_encode($raw), true);
        if (! is_array($t)) {
            continue;
        }
        foreach ($t as $code => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            if ((isset($fields['title']) && trim((string) $fields['title']) !== '')
                || (isset($fields['description']) && trim((string) $fields['description']) !== '')) {
                $langs[strtolower((string) $code)] = true;
            }
        }
    }

    return $memo[$app][$storeId] = array_keys($langs);
}

/**
 * The locales a store should expose to search engines: its default language, plus
 * every language it BOTH offers (menu_languages) AND has real translations for.
 * Everything else consolidates onto the default. Cheap for the common single-
 * language store — the translation scan only runs when extra languages are listed.
 *
 * @return list<string> lowercase codes, default first
 */
function store_indexable_langs(int $storeId): array
{
    static $memo = [];
    static $stamp = null;
    $app = RequestEpoch::id();
    if ($stamp !== $app) {
        $memo = [];
        $stamp = $app;
    }
    if (isset($memo[$app]) && array_key_exists($storeId, $memo[$app])) {
        return $memo[$app][$storeId];
    }

    $default = strtolower((string) (store_options($storeId, 'default_language') ?: LaravelLocalization::getDefaultLocale()));
    $out = [$default];

    $offered = store_options($storeId, 'menu_languages');
    $offered = is_array($offered)
        ? array_values(array_filter(array_map(fn ($c) => strtolower((string) $c), $offered), fn ($c) => $c !== '' && $c !== $default))
        : [];
    if ($offered !== []) {
        $translated = store_translated_langs($storeId);
        foreach ($offered as $code) {
            if (in_array($code, $translated, true)) {
                $out[] = $code;
            }
        }
    }

    return $memo[$app][$storeId] = array_values(array_unique($out));
}

/**
 * A store is worth indexing only when it has at least one visible menu item — an
 * item-less store renders an empty page that Google flags as a Soft 404.
 */
function store_has_menu(int $storeId): bool
{
    static $memo = [];
    static $stamp = null;
    $app = RequestEpoch::id();
    if ($stamp !== $app) {
        $memo = [];
        $stamp = $app;
    }
    if (isset($memo[$app]) && array_key_exists($storeId, $memo[$app])) {
        return $memo[$app][$storeId];
    }

    return $memo[$app][$storeId] = Menu::where('store_id', $storeId)->where('active', '1')->whereNull('deleted_at')->exists();
}

/**
 * Storefront hreflang cluster scoped to a store's real (translated) languages —
 * the store-aware sibling of seo_hreflang_tags(). Emits nothing in session (non-
 * prefix) mode or when the store is effectively single-language, so Google is
 * never told a phantom locale variant exists.
 */
function seo_hreflang_tags_for_store(int $storeId): string
{
    if (! config('settings.include_language_code')) {
        return '';
    }
    $indexable = store_indexable_langs($storeId);
    if (count($indexable) < 2) {
        return ''; // one real language → no alternates; the canonical self-references
    }

    $tags = [];
    foreach ($indexable as $code) {
        $url = LaravelLocalization::getLocalizedURL($code, null, [], false);
        if ($url) {
            $url = store_primary_url($storeId, $url); // P1-20: alternates live on the primary address
            $tags[] = '<link rel="alternate" hreflang="'.e($code).'" href="'.e($url).'" />';
        }
    }
    // x-default = the store's default-language URL (the first, unprefixed entry).
    $default = LaravelLocalization::getLocalizedURL($indexable[0], null, [], false);
    if ($default) {
        $default = store_primary_url($storeId, $default);
        $tags[] = '<link rel="alternate" hreflang="x-default" href="'.e($default).'" />';
    }

    return implode("\n    ", $tags);
}

/**
 * Canonical URL for a storefront page: self when the current locale is one the
 * store genuinely publishes, else the store's default-locale URL — so phantom and
 * non-offered locale variants consolidate to the single indexable page instead of
 * self-canonicalising into a "Google chose a different canonical" error.
 */
function store_canonical_url(int $storeId): string
{
    // P1-20 (J28): whatever address the page was reached on, the canonical is the primary address.
    return store_primary_url($storeId, store_canonical_url_on_this_host($storeId));
}

/** P1-20: the same storefront page on the store's primary address (see StoreAddressResolver::rebase). */
function store_primary_url(int $storeId, string $url): string
{
    try {
        return app(StoreAddressResolver::class)->rebase($storeId, $url);
    } catch (Throwable) {
        return $url;
    }
}

/** The pre-P1-20 canonical rule (locale consolidation), on the host the page was asked on. */
function store_canonical_url_on_this_host(int $storeId): string
{
    if (! config('settings.include_language_code')) {
        return url()->current();
    }
    $indexable = store_indexable_langs($storeId);
    // Key off the URL-PREFIX locale, NOT get_lang(): a request to /de/{slug} on a
    // store that doesn't offer German has its CONTENT locale fall back to the store
    // default (get_lang() == 'ar'), which is offered — so get_lang() would wrongly
    // self-canonicalise the /de/ duplicate. The first path segment is the real
    // prefix locale (or not a locale at all on the unprefixed default URL).
    $seg = strtolower((string) request()->segment(1));
    $active = array_map('strtolower', array_keys(get_active_languages()));
    $urlLocale = in_array($seg, $active, true) ? $seg : strtolower((string) LaravelLocalization::getDefaultLocale());
    if (in_array($urlLocale, $indexable, true)) {
        return url()->current();
    }
    // Consolidate the non-offered locale variant onto the store's default URL.
    $url = LaravelLocalization::getLocalizedURL($indexable[0], url()->current(), [], false);

    return $url ?: url()->current();
}

/**
 * Normalise a user-entered external link into a SAFE, absolute href. A link stored
 * without a scheme ("instagram.com/x", "tiktok.com/@name") otherwise renders as a
 * RELATIVE href that a crawler resolves against the current host —
 * juwri.com/instagram.com/x → 404 (a whole "Not found" bucket in Search
 * Console). This upgrades a scheme-less-but-plausible host to https://, passes
 * explicit schemes / in-site paths / anchors through untouched, and collapses
 * un-linkable junk (a bare word, a lone @handle with no host) to '#' so it can
 * never become a crawlable 404.
 */
function external_url(?string $url): string
{
    $u = trim((string) $url);
    if ($u === '' || $u === '#') {
        return '#';
    }
    // explicit, allowed schemes — trust as-is
    if (preg_match('#^(https?:|mailto:|tel:|whatsapp:|sms:)#i', $u)) {
        return $u;
    }
    // in-site anchor or absolute path — the browser resolves it on-site correctly
    if ($u[0] === '#' || $u[0] === '/') {
        return $u;
    }
    // protocol-relative //host/path
    if (str_starts_with($u, '//')) {
        return 'https:'.$u;
    }
    // scheme-less but a real host ("instagram.com/x", "sub.example.co.uk/p") → https
    $host = (string) strtok($u, '/');
    if (! str_contains($host, '@')
        && ! str_contains($host, ' ')
        && preg_match('#^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$#i', $host)) {
        return 'https://'.$u;
    }

    // a bare word, a lone @handle, spaces — not a linkable URL → dead anchor
    return '#';
}

/**
 * Keep a stored storefront link on the visitor's CURRENT locale.
 *
 * Menu banners / story CTAs store a full URL the owner (or the demo seeder)
 * typed, and those are almost always written unprefixed — `/{slug}?item=12`.
 * In URL-prefix mode that URL IS the default-locale page, so an English diner
 * who tapped a banner was thrown into the store's Arabic default: the reported
 * "clicking an item changed the language".
 *
 * Only rewrites links that belong to THIS site; an external link (the owner's
 * Instagram, a delivery partner) is returned untouched, as is anything in
 * session mode, where one URL serves every locale.
 */
function storefront_localized_link(?string $url): ?string
{
    if (! is_string($url) || trim($url) === '') {
        return $url;
    }
    if (! config('settings.include_language_code')) {
        return $url;
    }

    $trimmed = trim($url);
    // Leave anchors, mailto:, tel:, javascript: and protocol-relative hosts alone.
    if (preg_match('/^(#|mailto:|tel:|javascript:|\/\/)/i', $trimmed)) {
        return $url;
    }

    $host = parse_url($trimmed, PHP_URL_HOST);
    if ($host !== null && strcasecmp($host, (string) parse_url(config('app.url'), PHP_URL_HOST)) !== 0
        && strcasecmp($host, (string) request()->getHost()) !== 0) {
        return $url; // someone else's site
    }

    try {
        $localized = LaravelLocalization::getLocalizedURL(get_lang(), $trimmed, [], false);
    } catch (Throwable $e) {
        return $url;
    }

    return $localized ?: $url;
}
