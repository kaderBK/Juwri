<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\Order;
use App\Models\Story;
use App\Services\Directory\OpeningHoursService;
use App\Services\FeatureAccess;
use App\Support\StoreClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\BranchHourException;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Store\StoreStatus;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StorePath;

// Storefront display helpers kept OUT of the god-file Helper.php so it stays under
// its size ceiling (ControllerSizeGuardTest). Autoloaded via composer.json "files".

if (! function_exists('storefront_hours_today')) {
    /**
     * Today's opening window for a store, formatted "09:00–23:00" (en dash), or null
     * when there are no usable hours or the store is closed all day today. Reads the
     * same weekly model the directory open/closed badge uses (branch hours → timing
     * text). Timezone follows the configured branch, else the app default. Never throws.
     */
    function storefront_hours_today(?Store $store): ?string
    {
        if ($store === null) {
            return null;
        }

        try {
            $week = OpeningHoursService::weeklyModelForPost($store);
            if (empty($week)) {
                return null;
            }

            $branch = $store->branches()->where('active', true)->whereNotNull('hours')->first();
            $tz = ($branch && $branch->timezone) ? $branch->timezone : config('app.timezone', 'UTC');

            $now = Carbon::now($tz);
            $key = OpeningHoursService::DAYS[$now->dayOfWeekIso - 1];
            $today = $week[$key] ?? null;

            if (! $today || ! empty($today['closed']) || empty($today['open']) || empty($today['close'])) {
                return null;
            }

            // Wrap in LTR isolates (LRI…PDI) so the "09:00–23:00" range never gets
            // bidi-reordered into "23:00–09:00" inside an RTL (Arabic) header.
            return "\u{2066}".$today['open'].'–'.$today['close']."\u{2069}";
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('store_open_now')) {
    /**
     * Whether the store is accepting orders RIGHT NOW: the owner's manual open/close
     * switch AND its configured opening hours must both allow it. P6-290:
     * a single resolver (StoreStatus::for) when any active branch has hours OR
     * any active branch has a branch_hour_exception for today/yesterday; the
     * legacy `posts.timing` text model (OpeningHoursService::weeklyModelForPost)
     * stays as the fallback for the old "store never configured structured
     * hours" path. Fail-CLOSED on an error when the store actually has any
     * hours/exceptions configured (don't trap a live store as closed because of
     * a bad timezone or a malformed hours row); fail-OPEN otherwise.
     */
    function store_open_now(?Store $store): bool
    {
        if ($store === null) {
            return true;
        }

        try {
            // Owner master switch: '0' force-closes; '1' / '' / missing = open.
            $manual = store_options($store->id, 'open_close_store');
            if ($manual !== false && (string) $manual === '0') {
                return false;
            }

            $activeBranches = $store->branches()->where('active', true)->get();
            $hasAnyHours = $activeBranches->contains(fn ($b) => ! empty($b->hours));
            $hasAnyException = false;
            if (! $hasAnyHours && $activeBranches->isNotEmpty()) {
                $tz = $activeBranches->firstWhere(fn ($b) => ! empty($b->timezone))?->timezone
                    ?: config('app.timezone', 'UTC');
                try {
                    $now = Carbon::now($tz);
                } catch (Throwable $e) {
                    $now = Carbon::now();
                }
                $today = $now->format('Y-m-d');
                $yesterday = $now->copy()->subDay()->format('Y-m-d');
                $ids = $activeBranches->pluck('id')->all();
                if ($ids !== []) {
                    $hasAnyException = BranchHourException::query()
                        ->whereIn('branch_id', $ids)
                        ->whereIn('date', [$yesterday, $today])
                        ->exists();
                }
            }

            // The single resolver path: structured hours OR an exception applies.
            if ($hasAnyHours || $hasAnyException) {
                return StoreStatus::for($store)['open'];
            }

            // No active branches at all → closed (the storefront refuses the order).
            if ($activeBranches->isEmpty()) {
                return false;
            }

            // Active branches but none have hours and no exception → fall back to
            // the legacy `posts.timing` text model. `weeklyModelForPost` prefers a
            // branch with structured hours; with no such branch it parses the
            // free-text field. Unknown / empty → open (matches the prior fail-open
            // behaviour for stores that never configured anything).
            $week = OpeningHoursService::weeklyModelForPost($store);
            if (empty($week)) {
                return true;
            }
            $tz = $activeBranches->firstWhere(fn ($b) => ! empty($b->timezone))?->timezone
                ?: config('app.timezone', 'UTC');
            $status = OpeningHoursService::isOpenAt($week, $tz);

            return $status['state'] !== 'closed';
        } catch (Throwable $e) {
            try {
                Log::warning('store_open_now failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            } catch (Throwable $ignored) {
                // never let logging mask the result
            }
            try {
                $hasAnyHours = $store->branches()->where('active', true)->whereNotNull('hours')->exists();
                if ($hasAnyHours) {
                    return false; // fail-closed: we have hours configured, don't claim open on an unknown
                }
                $hasAnyException = BranchHourException::query()
                    ->whereIn('branch_id', $store->branches()->where('active', true)->pluck('id')->all() ?: [0])
                    ->exists();
                if ($hasAnyException) {
                    return false; // fail-closed: an exception is configured, honour it
                }
            } catch (Throwable $ignored) {
                return false;
            }

            return true; // fail-open: no hours, no exceptions configured
        }
    }
}

if (! function_exists('store_accepts_orders_now')) {
    /**
     * P6-346 + P6-347: the store-level "can the storefront accept an order right now?" gate.
     *
     * Honours the typed setting `prevent_order_on_close` ("Prevent orders when closed",
     * default ON; the owner's saved value is read through {@see StoreSettings}). When the
     * setting is explicitly OFF ('0' / 0 / false) the opening-hours check is SKIPPED — the
     * storefront still answers orders while the hours say closed. The owner's manual
     * `open_close_store = '0'` switch and any active branch with `accepting_orders = false`
     * still block.
     *
     * When the weekly model comes from structured branch hours (any active branch has its
     * `hours` JSON set), the store is open iff at least one such branch is open NOW and
     * accepting orders — the same shape {@see StoreStatus::for()} uses for the marketplace.
     * When the weekly model comes from the free-text `timing` field, hours are evaluated
     * with the STORE timezone ({@see StoreClock::tz()}), not the first branch's (which is
     * often an un-houred auto-created "Main" branch and would fall back to the app tz,
     * wrong for KSA). Fail-open on any Throwable, like {@see store_open_now()}.
     *
     * @param  Store|null  $store  the store, or null (= open, matches store_open_now)
     */
    function store_accepts_orders_now(?Store $store): bool
    {
        if ($store === null) {
            return true;
        }

        try {
            // 1. Owner master switch: '0' force-closes; '' / '1' / missing = open.
            $manual = store_options($store->id, 'open_close_store');
            if ($manual !== false && (string) $manual === '0') {
                return false;
            }

            // 2. P6-346: decide whether the opening-hours check applies. Stored as
            // '0' / 0 / false when the owner disabled it; null/missing (the default)
            // behaves as ON, i.e. today's behaviour. Anything truthy also runs the check.
            $raw = StoreSettings::get((int) $store->id, 'prevent_order_on_close');
            $skipHours = ($raw === '0' || $raw === 0 || $raw === false);

            // 3. Paused: every active branch has accepting_orders = false (one paused branch of several does not close the store),
            // even when the hours check is skipped — "a paused branch still block".
            $activeAll = Branch::where('store_id', $store->id)->where('active', true)->get();
            $allPaused = $activeAll->isNotEmpty()
                && $activeAll->every(fn ($b) => ! $b->accepting_orders);
            if ($allPaused) {
                return false;
            }

            // 4. Opening-hours check (skipped when prevent_order_on_close is explicitly off).
            if (! $skipHours) {
                // P6-290 (kept through the P6-346 gate): no active branch → closed; a closed /
                // special-hours exception for today or yesterday goes through the single resolver.
                if ($activeAll->isEmpty()) {
                    return false;
                }
                $exTz = $activeAll->firstWhere(fn ($b) => ! empty($b->timezone))?->timezone ?: StoreClock::tz($store->id);
                try {
                    $exNow = Carbon::now($exTz);
                } catch (Throwable $e) {
                    $exNow = Carbon::now();
                }
                if (BranchHourException::query()
                    ->whereIn('branch_id', $activeAll->pluck('id')->all())
                    ->whereIn('date', [$exNow->copy()->subDay()->format('Y-m-d'), $exNow->format('Y-m-d')])
                    ->exists()) {
                    return StoreStatus::for($store)['open'];
                }

                $structuredBranches = $activeAll->filter(fn ($b) => $b->hours !== null);

                if ($structuredBranches->isNotEmpty()) {
                    // P6-347: structured branch hours — open if ANY active branch is open
                    // and accepting right now (StoreStatus::for-style), not just the first.
                    $anyOpen = false;
                    foreach ($structuredBranches as $b) {
                        if (empty($b->hours)) {
                            continue;
                        }
                        if (! (bool) ($b->accepting_orders ?? true)) {
                            continue;
                        }
                        $bWeek = OpeningHoursService::fromHoursJson($b->hours);
                        if ($bWeek === null) {
                            continue;
                        }
                        $bTz = $b->timezone ?: StoreClock::tz($store->id);
                        $bState = OpeningHoursService::isOpenAt($bWeek, $bTz);
                        if ($bState['state'] !== 'closed') {
                            $anyOpen = true;
                            break;
                        }
                    }
                    if (! $anyOpen) {
                        return false;
                    }
                } else {
                    // Free-text timing path — read the weekly model and evaluate it in the
                    // STORE timezone (StoreClock::tz), so a KSA store with timing "10:00-23:00"
                    // and tz Asia/Riyadh is closed at 00:30 Riyadh even when the app timezone
                    // is UTC.
                    $week = OpeningHoursService::fromTimingText($store->timing ?? null);
                    if ($week !== null) {
                        $tz = StoreClock::tz($store->id);
                        $status = OpeningHoursService::isOpenAt($week, $tz);
                        if ($status['state'] === 'closed') {
                            return false;
                        }
                    }
                }
            }

            return true;
        } catch (Throwable $e) {
            return true;
        }
    }
}

if (! function_exists('store_hours_payload')) {
    /**
     * The STATIC open/close inputs for a store — weekly hours model, timezone and
     * the manual force-closed switch — so the "Open now / Closed" chip can be
     * recomputed CLIENT-side from the visitor's clock instead of being baked into
     * the HTML at render time. That baked-in state is what made the storefront
     * full-page cache unsafe for stores with hours (a page cached while open kept
     * saying "Open" after closing). This payload is time-INDEPENDENT, so it is safe
     * to cache; the JS in the layout does the per-request open/closed calculation.
     *
     * Returns null when there is nothing to compute client-side — an always-open
     * store (no hours configured and not force-closed) whose static "Open now" is
     * correct forever. Never throws.
     *
     * P6-290: also reports `closedToday` so the client-side recompute can force
     * "closed" when a branch_hour_exceptions row closes the store today —
     * otherwise the chip would show "Open" from the weekly entry while the
     * actual rule says closed. Time-INDEPENDENT (safe to cache; the partial
     * decides what to do).
     *
     * @return array{week: array<string, array{open?: string, close?: string, closed?: bool}>|null, tz: string, manualClosed: bool, closedToday: bool, labels: array{open: string, closed: string}}|null
     */
    function store_hours_payload(?Store $store): ?array
    {
        if ($store === null) {
            return null;
        }

        try {
            $manual = store_options($store->id, 'open_close_store');
            $manualClosed = ($manual !== false && (string) $manual === '0');

            $week = OpeningHoursService::weeklyModelForPost($store);

            // Always open (no hours, not force-closed): the server's static "Open
            // now" never goes stale — no client recompute needed.
            if (! $manualClosed && empty($week)) {
                return null;
            }

            $branch = $store->branches()->where('active', true)->whereNotNull('hours')->first();
            $tz = ($branch && $branch->timezone) ? $branch->timezone : config('app.timezone', 'UTC');

            // P6-290: a closed branch_hour_exception for TODAY must force the
            // client chip to "closed" — the weekly entry above says open. A
            // "closed today" row wins over the weekly row; the JS consumes the
            // flag below.
            $closedToday = false;
            try {
                $now = Carbon::now((string) $tz);
                $todayStr = $now->format('Y-m-d');
                $branchIds = $store->branches()->where('active', true)->pluck('id')->all();
                if ($branchIds !== []) {
                    $closedToday = BranchHourException::query()
                        ->whereIn('branch_id', $branchIds)
                        ->where('date', $todayStr)
                        ->where('closed', true)
                        ->exists();
                }
            } catch (Throwable $ignored) {
                // never let the exception check break the payload
                $closedToday = false;
            }

            // Labels are localized here (not in the Blade layout) so the layout can
            // stay a single @if without a @php block, and are cached per-locale
            // (the storefront cache key includes the locale).
            return [
                'week' => $week ?: null,
                'tz' => (string) $tz,
                'manualClosed' => $manualClosed,
                'closedToday' => $closedToday,
                'labels' => ['open' => ___('Open now'), 'closed' => ___('Closed')],
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('storefront_theme_root_class')) {
    /**
     * The wrapper class a theme scopes its CSS tokens under (what its own
     * storefront pages put on their outer <div>). Usually the theme name; a few
     * themes differ. Used by shared views (e.g. the info page) that must adopt a
     * theme's look without shipping a per-theme blade.
     */
    function storefront_theme_root_class(string $theme): string
    {
        return [
            'vivid' => 'vd',
            'crumb' => 'app',
            'scoop' => 'stage scoop',
        ][$theme] ?? $theme;
    }
}

if (! function_exists('storefront_delivery_coords')) {
    /**
     * Normalise the delivery pin a storefront diner submits with an order. Themes
     * historically name the hidden lat/lng inputs differently — address_lat/
     * address_lng (aurora/vivid/feast), latitude/longitude (velvet), or the
     * canonical delivery_lat/delivery_lng — so accept any of the three. Returns
     * [lat, lng] only when BOTH are present and in valid WGS84 range, else
     * [null, null]. Never throws: a bad or missing pin just yields no coordinates,
     * never a 500, so an order is never blocked by its map data.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: float|null, 1: float|null}
     */
    function storefront_delivery_coords(array $input): array
    {
        $pairs = [
            ['delivery_lat', 'delivery_lng'],
            ['address_lat', 'address_lng'],
            ['latitude', 'longitude'],
        ];

        foreach ($pairs as [$latKey, $lngKey]) {
            $lat = $input[$latKey] ?? null;
            $lng = $input[$lngKey] ?? null;

            if ($lat === null || $lng === null || $lat === '' || $lng === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $lat = (float) $lat;
            $lng = (float) $lng;

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                continue;
            }

            return [$lat, $lng];
        }

        return [null, null];
    }
}

if (! function_exists('order_delivery_maps_url')) {
    /**
     * A Google-Maps link to a delivery order's pinned drop-off, or null when the
     * order carries no coordinates. The diner's pin is saved on the order
     * (orders.delivery_lat/lng) but was only ever machine-read by dispatch — this
     * lets the owner order screen, the kitchen ticket and the order notifications
     * give the person who actually delivers a tappable map.
     *
     * @param  Order|object|null  $order
     */
    function order_delivery_maps_url($order): ?string
    {
        if (! is_object($order)) {
            return null;
        }

        $lat = $order->delivery_lat ?? null;
        $lng = $order->delivery_lng ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return 'https://www.google.com/maps?q='.rawurlencode($lat.','.$lng);
    }
}

if (! function_exists('storefront_apply_store_currency')) {
    /**
     * Point the currency formatters (price_symbol_format() and friends, which all
     * read `settings.currency_*`) at ONE store's currency for the rest of this
     * request.
     *
     * The storefront render path does this inline in StoreController@publicView, so
     * every price a theme prints is in the store's own currency. A storefront JSON
     * endpoint that never goes through that render — the loyalty balance strip is
     * the first — formats money in the PLATFORM currency instead, and a dirham
     * store's checkout showed its loyalty reward as "$10.00" beside ⃃ totals.
     *
     * Deliberately mirrors, rather than replaces, the two existing inline copies in
     * StoreController (publicView and submitOrder): those run inside long methods with
     * their own surrounding order, and folding them in belongs to a currency-focused
     * change, not to a caller that only needs the same effect. Keep the three in
     * step — the fallbacks (USD, platform currency_pos) are load-bearing for stores
     * that never picked a currency.
     */
    function storefront_apply_store_currency(int|string $storeId): void
    {
        remember_platform_currency(); // bug batch 10 · 1
        config(['settings.currency_sign' => store_options($storeId, 'currency_sign')]);
        config(['settings.currency_pos' => store_currency_pos($storeId)]);
        config(['settings.currency_code' => (string) (store_options($storeId, 'currency_code') ?: 'USD')]);
        config(['settings.currency_rtl_auto' => store_options($storeId, 'currency_rtl_auto', '1')]);
    }
}

if (! function_exists('storefront_page_has_checkout')) {
    /**
     * Whether the page being rendered can actually place an order.
     *
     * The storefront index (StoreController@publicView) is the only page that
     * renders a checkout — the SPA themes hold cart/checkout as views inside it,
     * and the kiosk just points at the same index with ?kiosk=1. account,
     * profile, track and the info pages all extend the SAME layout but have no
     * checkout, so every checkout-only control the layout injects (tip presets,
     * loyalty redeem, the order-options panel) has to gate on this or it strands
     * itself on a page with nothing to attach to.
     *
     * Matched by action method, not route name, so the legacy old-url variant
     * (an unnamed GET that also resolves to publicView) counts too.
     */
    function storefront_page_has_checkout(): bool
    {
        $route = request()->route();
        if ($route === null) {
            return false;
        }

        return str_ends_with((string) $route->getActionName(), 'StoreController@publicView');
    }
}

if (! function_exists('storefront_catering_enabled')) {
    /**
     * Whether this store's catering request form should be offered: the owner's
     * switch AND the entitlement (kill-switch / plan). One definition, because a
     * theme that renders its own entry point for the form — a button opening a
     * sheet, say — has to gate that button on exactly what the form gates itself
     * on, or the storefront grows a control that opens nothing.
     */
    function storefront_catering_enabled(int|string $storeId): bool
    {
        return ! empty(store_options($storeId, 'catering_enabled'))
            && FeatureAccess::storeAllows((int) $storeId, 'catering');
    }
}

if (! function_exists('storefront_info_page_labels')) {
    /**
     * Canonical slug => label map for the owner-authored storefront info pages
     * (Terms / Privacy / About / Help). One source of truth shared by the settings
     * editor, the public page controller and every theme's account footer. The
     * option row for slug `x` is `sf_page_x`; labels are translated at call time.
     *
     * @return array<string, string>
     */
    function storefront_info_page_labels(): array
    {
        return [
            'terms' => ___('Terms & Conditions'),
            'privacy' => ___('Privacy Policy'),
            'about' => ___('About Us'),
            'help' => ___('Help & FAQ'),
            // HPMS E14(d): additional owner-authored storefront pages.
            'faq' => ___('FAQ'),
            'refund_policy' => ___('Refund Policy'),
            'delivery_policy' => ___('Delivery Policy'),
        ];
    }
}

if (! function_exists('storefront_info_pages')) {
    /**
     * The subset of info pages this store has actually written content for, as
     * slug => label. Empty when the owner has filled none in — themes use this to
     * show the links only when there is somewhere to go (no dead links).
     *
     * @return array<string, string>
     */
    function storefront_info_pages(int|string $storeId): array
    {
        $out = [];
        foreach (storefront_info_page_labels() as $slug => $label) {
            $body = (string) store_options($storeId, 'sf_page_'.$slug, '');
            if (trim($body) !== '') {
                $out[$slug] = $label;
            }
        }

        return $out;
    }
}

if (! function_exists('stories_entry_for')) {
    /**
     * Stories Entry Points ("SE" run) — resolve which story entry-point style the
     * standalone display themes (folio, pageview) should render for a store.
     *
     * Resolution chain (rev/folio-stories/SPEC.md §2):
     *   1. Store-level customizer select — theme_setting($storeId, 'stories.entry').
     *      Any §1 slug, or 'inherit'/unset to defer.
     *   2. inherit/unset → platform default PlatformSettings::get('stories_entry_default', false),
     *      set by the super admin on /admin/menu-templates.
     *   3. Option row missing / value invalid → code fallback 'ribbon'.
     *
     * Returns 'none' (so no entry renders) when the resolved slug is 'none' OR the
     * store has zero *publishable* stories (active + within any starts_at/expires_at
     * window — the same Story::visible() gate the public feed uses). This keeps the
     * mount off empty feeds. There is NO dependency on the legacy stories.fallback_rail
     * toggle — that belongs to the untouched legacy shared rail.
     *
     * Per-request memo lives on the request object (mirrors theme_setting): object
     * ids are recycled, and a $storeId-keyed static would collide across the fresh
     * RefreshDatabase ids of two tests / leak between requests under a long-lived
     * worker. Dying with the request avoids both. Lives here, not in the god-file
     * Helper.php, to keep that under its shrinking ceiling (ControllerSizeGuardTest).
     */
    function stories_entry_for(int $storeId): string
    {
        $valid = ['none', 'ribbon', 'orb', 'toolbar', 'capsule', 'tassel', 'seal', 'dogear', 'filmstrip', 'medallion', 'sheet'];

        try {
            $__request = request();
            $__cache = $__request->attributes->get('__stories_entry_memo', []);
            if (array_key_exists($storeId, $__cache)) {
                return $__cache[$storeId];
            }

            // 1. Store-level customizer select.
            $slug = theme_setting($storeId, 'stories.entry', 'inherit');
            $slug = is_string($slug) ? trim($slug) : 'inherit';

            // 2. inherit / unset → super-admin platform default.
            if ($slug === '' || $slug === 'inherit') {
                $default = PlatformSettings::get('stories_entry_default', false);
                $slug = is_string($default) ? trim($default) : '';
            }

            // 3. Missing / unknown value → code fallback.
            if (! in_array($slug, $valid, true)) {
                $slug = 'ribbon';
            }

            // Gate: an entry renders only when at least one story is publishable.
            if ($slug !== 'none'
                && ! Story::where('store_id', $storeId)->visible()->exists()) {
                $slug = 'none';
            }

            $__cache[$storeId] = $slug;
            $__request->attributes->set('__stories_entry_memo', $__cache);

            return $slug;
        } catch (Throwable $e) {
            return 'none';
        }
    }
}

if (! function_exists('store_url')) {
    /**
     * P1-20 (J28): THE URL builder for a store — absolute URL of $path inside the store's PRIMARY address
     * (verified primary custom domain / platform subdomain, else `<storefront base>/{slug}`). Use it for links,
     * emails, QR codes, WhatsApp/SMS, payment return URLs, sitemaps and canonical tags. Webhooks stay on the
     * platform host (route()).
     *
     * @param  Store|int|string  $store  Store model (preferred), store id or slug
     * @param  string  $path  store-relative path ('', 'bio', 'reservation/deposit/abc'); can never change the host
     */
    function store_url($store, string $path = ''): string
    {
        return app(StoreAddressResolver::class)->url($store, $path);
    }
}

if (! function_exists('store_sw_scope')) {
    /**
     * P1-20 (J28): service-worker / PWA scope of the store page being rendered — "/" on the store's own origin
     * (verified custom domain or platform subdomain), else its path prefix on the shared host ("/{slug}", with
     * the locale prefix when the URL carries one).
     */
    function store_sw_scope(Store|string $store): string
    {
        $request = request();
        if ($request->attributes->has(HostMap::STORE_ATTRIBUTE)) {
            return '/';
        }
        $slug = (string) (is_object($store) ? ($store->slug ?? '') : $store);
        ['locale' => $locale, 'segments' => $segments] = StorePath::split($request->getPathInfo());
        $prefix = ($locale !== null && rawurldecode($segments[0] ?? '') === $slug) ? '/'.$locale : '';

        return $prefix.'/'.rawurlencode($slug);
    }
}

if (! function_exists('effective_public_url')) {
    /**
     * Effective public storefront URL for a store (outbound, absolute) — kept for its many callers
     * (JSON-LD, Google Wallet, order links, Reserve-with-Google feeds, bio links); since P1-20 it is
     * store_url($store): the store's primary address.
     *
     * @param  Store|string  $store  Store model (preferred) or a raw slug string.
     */
    function effective_public_url($store): string
    {
        $slug = is_object($store) ? ($store->slug ?? null) : (string) $store;
        if ($slug === null || $slug === '') {
            return url('/');
        }

        return store_url(is_object($store) ? $store : $slug);
    }
}

if (! function_exists('bio_public_url')) {
    /**
     * BIOLINK-TPL A1: the public bio-page URL for a store, with an optional `src`
     * attribution param. THE one helper every non-bio surface uses to link to a
     * store's bio page (QR designer, campaign composers, GBP, Wallet passes,
     * directory outbound) so the attribution bucket + the domain-correct origin
     * live in one place.
     *
     * Built on effective_public_url() — the single source of truth for a store's
     * public origin — so a reseller/owner custom-domain white-label store links to
     * its bio page on ITS OWN domain ("https://{domain}/bio"), and a platform-host
     * store links at "{slug}/bio" (identical to route('bio.public', slug)).
     *
     * The `src` values the bio page classifies are qr | social | direct | share
     * | other (BioPublicController::classifySource); an unknown value is sanitised
     * to word chars and capped, and the page falls it back to a referrer bucket.
     * `src` is appended verbatim to whatever query the origin already carries.
     *
     * @param  Store|string  $store  Store model (preferred) or a raw slug.
     */
    function bio_public_url($store, ?string $src = null): string
    {
        // The bio page lives at "{origin}/bio": at the custom-domain root for a
        // white-label store, else at the platform-host "{slug}/bio" route. A slug
        // that could not resolve an origin (empty) degrades to the site root + /bio.
        $origin = effective_public_url($store);
        $url = rtrim($origin, '/').'/bio';

        $src = $src !== null ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $src) : '';
        if (is_string($src) && $src !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'src='.urlencode(mb_substr($src, 0, 64));
        }

        return $url;
    }
}
