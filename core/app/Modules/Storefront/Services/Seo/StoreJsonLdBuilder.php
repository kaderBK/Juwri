<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Seo;

use App\Models\MenuCategory;
use App\Models\StoreReview;
use Illuminate\Support\Carbon;
use Juwri\Core\Contracts\Seo\StoreJsonLd;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\StoreReviewsDisplay;

/**
 * DIRECTORY L1 T1-2: Restaurant JSON-LD (schema.org) for AEO/SEO. P1-13: the storefront part lives in Storefront;
 * the unclaimed directory listing variant is the Marketplace module's ListingJsonLd.
 *
 * `forStore()` is wired into the Fusion store page head (marketplace/store.blade.php).
 *
 * `forListing()` is built for T3-3's unclaimed biz page, but NOT wired there —
 * that view (marketplace/directory/biz.blade.php) is L3-owned and still a P0-4
 * skeleton in this parallel wave; wiring a real body into another lane's file
 * risks a merge collision the run's ownership zones are explicit about
 * avoiding. L3 wires it with one line once T3-3 lands:
 *   Blade: `@php $jsonLd = \Juwri\Modules\Storefront\Services\Seo\StoreJsonLdBuilder::forListing($listing); @endphp`
 *   then:  `@if ($jsonLd)<script type="application/ld+json">{!! json_ld($jsonLd) !!}</script>@endif`
 *
 * SEAM (per STATUS.md's wave note under T1-2): aggregateRating is computed
 * directly from StoreReview here — T2-4's StoreRatingService does not exist
 * yet in this parallel wave. Same <3-review omission guard (D3-adjacent: a
 * rating built on 1-2 reviews is noise, not a trust signal) either way, so L2
 * can repoint this call to the cached service later without changing the
 * output contract.
 *
 * D3: `forListing()` NEVER emits aggregateRating — an unclaimed listing gets
 * no review wall, so it must never carry a reputation signal either.
 */
class StoreJsonLdBuilder implements StoreJsonLd
{
    /** P1-12: the Core contract other modules use (bound in StorefrontServiceProvider). */
    public function schemaRestaurant(Store $store): array
    {
        return self::forStore($store);
    }

    /** @return array<string, mixed> */
    public static function forStore(Store $store): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => $store->title,
            'url' => route('mk.store', $store->slug),
            'image' => self::storeImage($store),
            'address' => $store->address ? ['@type' => 'PostalAddress', 'streetAddress' => $store->address] : null,
            'geo' => self::geo($store->mk_lat ?? null, $store->mk_lng ?? null),
            'telephone' => $store->phone ?: null,
            'servesCuisine' => self::cuisineNames($store),
            'openingHoursSpecification' => self::parseOpeningHours($store->timing),
            'aggregateRating' => self::aggregateRating((int) $store->id),
            'hasMenu' => self::hasMenu($store),
            'priceRange' => self::priceRange($store),
        ], fn ($v) => $v !== null);
    }

    /**
     * F8 / google-suite B1: storefront (QR-menu) variant of forStore(). Same
     * Restaurant node but the canonical `url`/`@id` is the store's EFFECTIVE
     * public origin (reseller/custom domain when verified, else the platform
     * publicView route) so an indexed menu page becomes rich-result eligible on
     * whatever host actually serves it. Empty fields are omitted by the same
     * array_filter contract.
     *
     * B1 delta over the marketplace forStore():
     *  - url/@id via effective_public_url() (never the hardcoded platform host);
     *  - geo repointed from the marketplace mk_lat/mk_lng to a real Branch's
     *    latitude/longitude (active branch → first active branch with coords →
     *    mk_lat/mk_lng fallback);
     *  - aggregateRating GATED HERE (the storefront caller) on the per-store
     *    show_store_reviews opt-in — the shared aggregateRating() helper is left
     *    untouched so forStore()/marketplace output is unchanged;
     *  - adds acceptsReservations (post_option enable_reservations) and
     *    inLanguage (the store's active menu languages).
     *
     * @param  iterable<int, Language|string>  $menuLanguages  the
     *                                                         active menu-language set (Language models or bare codes) resolved
     *                                                         by StoreController; drives inLanguage. Empty → key omitted.
     * @return array<string, mixed>
     */
    public static function forStorefront(Store $store, ?Branch $activeBranch = null, iterable $menuLanguages = []): array
    {
        $url = effective_public_url($store);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            '@id' => $url,
            'name' => $store->title,
            'url' => $url,
            'image' => self::storeImage($store),
            'address' => $store->address ? ['@type' => 'PostalAddress', 'streetAddress' => $store->address] : null,
            'geo' => self::storefrontGeo($store, $activeBranch),
            'telephone' => $store->phone ?: null,
            'servesCuisine' => self::cuisineNames($store),
            'openingHoursSpecification' => self::parseOpeningHours($store->timing),
            'acceptsReservations' => self::acceptsReservations($store),
            // Gate at the CALLER, not inside aggregateRating() — that helper also
            // serves forStore()/marketplace, so editing it would change marketplace
            // output. show_store_reviews is a per-store opt-in (default OFF).
            'aggregateRating' => StoreReviewsDisplay::enabledFor((int) $store->id)
                ? self::aggregateRating((int) $store->id)
                : null,
            'inLanguage' => self::languageCodes($menuLanguages),
            // hasMenu is deliberately omitted on the storefront: the plan
            // menu-limit is a render-time grid gate (not applied to a raw active
            // query), so listing every active item would leak items an over-limit
            // store hides. The owner-gated MenuItem tree is added by
            // _partials/jsonld-menu.blade.php from the already-rendered $total_menus.
            // priceRange stays — a coarse $/$$/$$$ band, no item names.
            'priceRange' => self::priceRange($store),
        ], fn ($v) => $v !== null);
    }

    /**
     * B1: storefront geo prefers a real Branch coordinate over the marketplace
     * mk_lat/mk_lng. Priority: the active branch (when it carries coords) → the
     * store's first active branch that has both coords → the legacy mk_lat/mk_lng.
     *
     * @return array<string, mixed>|null
     */
    private static function storefrontGeo(Store $store, ?Branch $activeBranch): ?array
    {
        $branch = ($activeBranch && $activeBranch->latitude !== null && $activeBranch->longitude !== null)
            ? $activeBranch
            : Branch::where('store_id', $store->id)
                ->where('active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('position')
                ->first();

        if ($branch && $branch->latitude !== null && $branch->longitude !== null) {
            return self::geo((float) $branch->latitude, (float) $branch->longitude);
        }

        return self::geo($store->mk_lat ?? null, $store->mk_lng ?? null);
    }

    /**
     * B1: acceptsReservations from the per-store `enable_reservations` option.
     * Options arrive as the STRINGS '1'/'0' (or false when absent) — whitelist,
     * never a naive bool cast. Returns true when on, null (key omitted) otherwise.
     */
    private static function acceptsReservations(Store $store): ?bool
    {
        $value = store_options($store->id, 'enable_reservations');

        return in_array($value, [true, 1, '1', 'true', 'on'], true) ? true : null;
    }

    /**
     * B1: inLanguage from the store's active menu languages. Accepts Language
     * models or bare code strings; de-duplicates, preserves order. Empty → null.
     *
     * @param  iterable<int, Language|string>  $menuLanguages
     * @return array<int, string>|null
     */
    private static function languageCodes(iterable $menuLanguages): ?array
    {
        $codes = [];
        foreach ($menuLanguages as $lang) {
            $code = trim((string) (is_string($lang) ? $lang : ($lang->code ?? '')));
            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes ?: null;
    }

    private static function storeImage(Store $store): ?string
    {
        if (empty($store->main_image) || $store->main_image === 'default.png') {
            return null;
        }

        return media_url('storage/store/logo/', $store->main_image, $store->id);
    }

    private static function geo(?float $lat, ?float $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];
    }

    /** @return array<int, string>|null */
    private static function cuisineNames(Store $store): ?array
    {
        $names = $store->cuisines->map(fn ($c) => $c->displayName())->filter()->values()->all();

        return $names ?: null;
    }

    /**
     * A single stated daily range applied to every day of the week — the only
     * shape `posts.timing` reliably holds today (free text, e.g. "9:00 AM -
     * 11:00 PM"). Anything else omits the block rather than guessing; T5-2's
     * OpeningHoursService is the real per-day/overnight-span parser and
     * supersedes this once it lands (it is a different lane's task).
     */
    private static function parseOpeningHours(?string $timing): ?array
    {
        $timing = trim((string) $timing);
        if ($timing === '') {
            return null;
        }

        $parts = preg_split('/\s*(?:-|–|to)\s*/iu', $timing);
        if (! is_array($parts) || count($parts) !== 2) {
            return null;
        }

        $opens = self::to24h($parts[0]);
        $closes = self::to24h($parts[1]);
        if ($opens === null || $closes === null) {
            return null;
        }

        return [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'opens' => $opens,
            'closes' => $closes,
        ];
    }

    private static function to24h(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** D3-adjacent guard: a rating built on 1-2 reviews is noise, not signal. */
    private static function aggregateRating(int $storeId): ?array
    {
        $count = StoreReview::approved()->forStore($storeId)->count();
        if ($count < 3) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => round(StoreReview::approved()->forStore($storeId)->avg('stars'), 1),
            'reviewCount' => $count,
            'bestRating' => 5,
            'worstRating' => 1,
        ];
    }

    /**
     * AEO: dish names as plain, readable text — not buried in a description
     * blob. Capped (8 sections / 12 items) so the payload stays sane on a
     * large menu; this is a discovery signal, not a full menu mirror.
     */
    private static function hasMenu(Store $store): ?array
    {
        $categories = MenuCategory::where('store_id', $store->id)
            ->where('active', '1')->whereNull('parent')->orderBy('position')->limit(8)
            ->get(['id', 'name', 'translations']); // translations: the name accessor localises off it
        if ($categories->isEmpty()) {
            return null;
        }

        $sections = [];
        foreach ($categories as $category) {
            // get(...)->map (not query pluck) so the Menu name accessor localises;
            // translations must be selected for the accessor to read it.
            $items = Menu::where('store_id', $store->id)
                ->where('category_id', $category->id)->where('active', '1')
                ->orderBy('position')->limit(12)->get(['name', 'translations'])
                ->map(fn ($m) => (string) $m->name);
            if ($items->isEmpty()) {
                continue;
            }

            $sections[] = [
                '@type' => 'MenuSection',
                'name' => $category->name,
                'hasMenuItem' => $items->map(fn ($name) => ['@type' => 'MenuItem', 'name' => $name])->values()->all(),
            ];
        }

        return $sections ? ['@type' => 'Menu', 'hasMenuSection' => $sections] : null;
    }

    /** google-suite B2: total MenuItem cap — a page-weight bound, silently applied. */
    private const MENU_ITEM_CAP = 200;

    /**
     * F8b (owner-gated) + google-suite B2: build the schema.org Menu node from the
     * EXACT collection the storefront just rendered ($total_menus) rather than a
     * fresh active=1 query. That is the whole point — the render-time grid gate
     * (plan menu-limit, branch scope, availability window) has already been applied
     * to that set, so a Menu built from it can never surface an item the diner can't
     * see. Grouped by category in first-seen order.
     *
     * B2 enriches each MenuItem beyond the bare name: an Offer{price, priceCurrency}
     * (store currency + currency-correct decimals, honoring any in-memory branch
     * price override on the rendered model), localized description (via the model
     * accessor — the caller hands loaded models, never a pluck), image URL, and
     * suitableForDiet mapped from the veg `type` + is_vegan/is_halal/is_gluten_free
     * flags. The whole tree is capped at {@see self::MENU_ITEM_CAP} items total (a
     * silent page-weight bound — a huge menu never blows up the payload); items past
     * the cap are dropped and a category left with none is omitted.
     *
     * @param  iterable<Menu>  $menus  the rendered set (e.g. $total_menus)
     * @param  Store|null  $store  owning store (currency source); when null
     *                             the currency is resolved from the first item's store_id.
     * @return array<string, mixed>|null
     */
    public static function menuFromMenus(iterable $menus, ?Store $store = null): ?array
    {
        $byCat = [];        // category name => [MenuItem node, ...]; insertion order preserved
        $total = 0;
        $currency = null;   // resolved once (all items share one store)
        $decimals = 2;

        foreach ($menus as $menu) {
            if ($total >= self::MENU_ITEM_CAP) {
                break;
            }
            $name = trim((string) $menu->name);
            if ($name === '') {
                continue;
            }

            if ($currency === null) {
                $storeId = (int) ($store->id ?? $menu->store_id ?? 0);
                $currency = strtoupper($storeId > 0
                    ? store_currency($storeId)
                    : (string) config('settings.currency_code', 'USD'));
                $decimals = currency_decimals($currency);
            }

            $cat = trim((string) ($menu->category->name ?? ''));
            if ($cat === '') {
                $cat = ___('Menu');
            }

            $item = ['@type' => 'MenuItem', 'name' => $name];

            $description = trim((string) $menu->description);
            if ($description !== '') {
                $item['description'] = $description;
            }

            if (($image = self::menuImage($menu)) !== null) {
                $item['image'] = $image;
            }

            if (($diet = self::suitableForDiet($menu)) !== null) {
                $item['suitableForDiet'] = $diet;
            }

            // A priced item carries an Offer; a 0/absent price simply omits it.
            $priceVal = (float) $menu->price;
            if ($priceVal > 0) {
                $item['offers'] = [
                    '@type' => 'Offer',
                    'price' => number_format($priceVal, $decimals, '.', ''),
                    'priceCurrency' => $currency,
                ];
            }

            $byCat[$cat] ??= [];
            $byCat[$cat][] = $item;
            $total++;
        }

        $sections = [];
        foreach ($byCat as $cat => $items) {
            $sections[] = [
                '@type' => 'MenuSection',
                'name' => $cat,
                'hasMenuItem' => $items,
            ];
        }

        return $sections ? ['@type' => 'Menu', 'hasMenuSection' => $sections] : null;
    }

    /** B2: absolute URL for a menu item's primary image, or null when it has none. */
    private static function menuImage(Menu $menu): ?string
    {
        $image = trim((string) ($menu->image ?? ''));
        if ($image === '' || $image === 'default.png') {
            return null;
        }

        return media_url('storage/menu/', $image, $menu->store_id ? (int) $menu->store_id : null);
    }

    /**
     * B2: map a menu item to schema.org RestrictedDiet URLs from the veg `type`
     * enum and the explicit dietary booleans. `allergies` is free-text (no
     * RestrictedDiet enum equivalent) → deliberately not mapped. Empty → null.
     *
     * @return array<int, string>|null
     */
    private static function suitableForDiet(Menu $menu): ?array
    {
        $diets = [];
        if (trim((string) ($menu->type ?? '')) === 'veg') {
            $diets[] = 'https://schema.org/VegetarianDiet';
        }
        if ((bool) ($menu->is_vegan ?? false)) {
            $diets[] = 'https://schema.org/VeganDiet';
        }
        if ((bool) ($menu->is_halal ?? false)) {
            $diets[] = 'https://schema.org/HalalDiet';
        }
        if ((bool) ($menu->is_gluten_free ?? false)) {
            $diets[] = 'https://schema.org/GlutenFreeDiet';
        }

        return $diets ? array_values(array_unique($diets)) : null;
    }

    /** A rough tier from the average active item price — no currency conversion, just relative banding. */
    private static function priceRange(Store $store): ?string
    {
        $avg = Menu::where('store_id', $store->id)->where('active', '1')->avg('price_minor');
        if ($avg === null) {
            return null;
        }
        $avg = (float) $avg / (10 ** Currencies::exponent(store_currency($store->id))); // P1-05: minor → major

        $avg = (float) $avg;

        return match (true) {
            $avg < 5 => '$',
            $avg < 15 => '$$',
            $avg < 30 => '$$$',
            default => '$$$$',
        };
    }
}
