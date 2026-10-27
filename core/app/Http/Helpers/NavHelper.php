<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * owner dashboard navigation, report catalog and pin helpers.
 *
 * WI-M15-style split (the H11/M15 precedent for a global-function file, exactly
 * like WhatsAppHelper.php and GeoHelper.php): these dashboard-navigation helpers
 * were extracted verbatim from Helper.php to keep that god-file under its
 * shrinking line ceiling. They are global helpers, autoloaded via composer
 * "files" — every call site is unchanged.
 *
 * The single source of truth for the owner dashboard nav model, the topbar
 * search index it feeds, the feature-tab sub-navigation, the report catalog and
 * its pinned rows, plus the shared dashboard period resolver.
 */

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Models\MenuReview;
use App\Services\Entitlements;
use App\Services\FeatureRegistry;
use App\Services\FeatureSpotlight;
use App\Services\Verticals\VerticalRegistry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\FeatureCommerce;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Contracts\Marketplace\Directory;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Support\BrandContext;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * Single source of truth for the owner dashboard navigation model.
 * Both the sidebar (partials/nav-sidebar.blade.php) and the topbar search
 * (partials/topbar.blade.php) consume this so nav + search never drift.
 * Returns the tab → group → item tree (same shape the sidebar renders).
 */
function juwri_nav_model(): array
{
    // Memoise per request object AND route name: the active-state flags depend on the route, and the titles,
    // gates and entitlements on the locale/user. A process-wide static (the old code) outlived the request, so a
    // test (or an Octane/queue worker) that rendered the nav earlier under another locale/user/route state leaked
    // it into the next request that shared a route name. Within one request (sidebar + search both call this)
    // the object is identical, so the two still share one model.
    static $cache = null;
    $cache ??= new WeakMap;
    $__req = request();
    $__ck = (request()->route() && method_exists(request()->route(), 'getName') ? request()->route()->getName() : null) ?? '_none';
    // BL-036 A2b: staff see a filtered menu, so a model is never shared between the owner and a staff session.
    $__ck .= '|'.(auth()->id() ?? 0).'|'.(staff_session()->id ?? 'owner');
    if (isset($cache[$__req]) && array_key_exists($__ck, $cache[$__req])) {
        return $cache[$__req][$__ck];
    }

    $ctxStore = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
    $rid = $ctxStore->id ?? null;
    $actor = function_exists('dashboard_actor') ? dashboard_actor() : auth()->user();
    $owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();
    $isAdmin = ($actor && method_exists($actor, 'getAttribute') && ($actor->user_type ?? null) === 'admin');
    $on = fn ($p) => request()->routeIs($p);

    $__curLoc = LaravelLocalization::getCurrentLocale();
    $__defLoc = LaravelLocalization::getDefaultLocale();
    $__locPrefix = ($__curLoc && $__curLoc !== $__defLoc) ? $__curLoc.'/' : '';
    $settingsUrl = url($__locPrefix.'settings');
    $posUrl = url($__locPrefix.'pos');

    $pendingReviews = 0;
    if ($rid) {
        try {
            $pendingReviews = MenuReview::where('store_id', $rid)->where('status', 'pending')->count();
        } catch (Throwable $e) {
        }
    }
    $ownsReseller = false;
    try {
        $ownsReseller = $owner && app(ResellerContext::class)->ownsReseller((int) $owner->id, true);
    } catch (Throwable $e) {
    }
    /* FIX-7 F7-5 (gap audit): white-label leak. A merchant sitting under a reseller
       brand was still shown the PLATFORM's own storefront links (Add-ons, App Store,
       Theme marketplace) — clicking them takes the reseller's customer to us, with our
       branding and our billing. BrandContext is the established brand resolver
       (custom-domain host first, then the authenticated user's reseller_id); reuse it
       rather than inventing a second notion of "is this white-labelled". */
    $whiteLabel = false;
    try {
        $whiteLabel = BrandContext::current()->isReseller();
    } catch (Throwable $e) {
    }
    $hotelOn = false;
    $hotelAvail = false;
    try {
        $hotelOn = $rid ? app(RoomCharge::class)->enabled($rid) : false;
    } catch (Throwable $e) {
    }
    try {
        $hotelAvail = $rid ? app(RoomCharge::class)->available($rid) : false;
    } catch (Throwable $e) {
    }
    // DIRECTORY T2-3: the owner's store-review reply panel only makes sense
    // while the module is on — the route itself stays reachable either way
    // (an owner may still need to read a reply they posted before it went
    // dark), but advertising the link while the module is invisible would be
    // a dead end for the owner, so the nav entry follows the module state.
    $dirReviewsOn = false;
    try {
        $dirReviewsOn = app(Directory::class)->enabled();
    } catch (Throwable $e) {
    }

    // WIRING-RUN A1: every route carrying `feature:<key>` middleware must have the
    // SAME key gated here, otherwise the sidebar advertises links that 403 on click
    // (EnsureFeatureEnabled aborts regardless of plan). $feat mirrors that middleware.
    /* FIX-3 (gap audit): the gate is TRI-state, not boolean. A gated key the
       owner hasn't bought is not the same as a killed one: killed → hidden,
       unentitled → a LOCKED item linking to the upsell page. Collapsing the two
       (the old $feat) meant ten paid features were invisible to the people we
       wanted to sell them to. 'ok' | 'locked' | 'off'. */
    /* WI-F2 (upsell-visibility): the logic itself now lives in
       FeatureSpotlight::gateState() so the nav and every spotlight surface read
       one gate. The contract here is unchanged — 'ok' | 'locked' | 'off'. */
    $featState = fn (string $key): string => FeatureSpotlight::gateState($key, $owner);

    // Boolean view of the same gate — the contract WIRING-RUN A1 froze.
    $feat = fn (string $key): bool => $featState($key) === 'ok';

    $it = function ($route, $label, $icon, $pattern = null, $gate = true, $params = [], $badge = null) use ($on) {
        if (! $gate || ! Route::has($route)) {
            return null;
        }
        // BL-036 A2b: staff only see the pages their role and permissions open (the server still enforces).
        if (! EnsureStaffDashboardPermission::staffMayOpen($route)) {
            return null;
        }

        return ['url' => route($route, $params), 'label' => $label, 'icon' => $icon, 'active' => $on($pattern ?? $route), 'badge' => $badge ?: null];
    };
    $link = function ($url, $label, $icon, $active = false) {
        return ['url' => $url, 'label' => $label, 'icon' => $icon, 'active' => $active, 'badge' => null];
    };

    /* FIX-3: tri-state nav item for a sellable key. Entitled → the real link;
       unentitled → a locked item pointing at that key's upsell page; killed →
       null (hidden). Use this for EVERY key carrying `feature:`/`entitled:`
       middleware, so the sidebar can never advertise a link that 403s. */
    $gatedIt = function (string $key, $route, $label, $icon, $pattern = null, $params = [], $badge = null) use ($featState, $it, $link, $owner) {
        // P1-13 13c: a module that is not installed (Community: no Enterprise package, or unlicensed) has no route — hide it,
        // locked upsell row included (there is nothing to unlock on this install).
        if (! Route::has($route)) {
            return null;
        }
        // BL-036 A2b: staff never see upsell rows or links their permissions would 403.
        if (! EnsureStaffDashboardPermission::staffMayOpen($route)) {
            return null;
        }
        $state = $featState($key);
        if ($state === 'off') {
            return null;
        }
        if ($state === 'locked') {
            if (! Route::has('feature.locked')) {
                return null;
            }
            $active = request()->routeIs('feature.locked') && request()->route('key') === $key;

            /* WI-A1 (upsell-visibility): keep the feature's OWN icon. Swapping
               it for a padlock made every locked row look like the same
               anonymous locked thing; the row should still read as the feature
               it is, just dimmed, with the lock as a small chip beside it.
               The tooltip carries the shortest true sell, because a lock with
               no price gives nobody a reason to click. */
            /* WI-A3: a previewable key sends them to the real screen, blurred,
               rather than to a page describing it — showing beats telling, and
               the controller reduces the payload to safe aggregates. */
            $previewRoute = FeatureSpotlight::lockedDestination($key);
            $destination = $previewRoute ? route($previewRoute) : route('feature.locked', $key);
            $active = $active || ($previewRoute && request()->routeIs($previewRoute));

            $row = $link($destination, $label, $icon, $active);
            $row['locked'] = true;
            $row['feature_key'] = $key;
            $row['sell'] = FeatureSpotlight::chipLine($key, $owner);
            $row['tip'] = FeatureSpotlight::tooltip($key, $owner);

            return $row;
        }

        $row = $it($route, $label, $icon, $pattern, true, $params, $badge);

        /* WI-B2 (upsell-visibility): entitlement gained in the last fortnight
           gets a NEW dot. Entitlement usually arrives while the owner is
           somewhere else — a plan upgrade, an app install, an admin grant — so
           without this the only signal is a nav row quietly appearing. */
        if ($row && FeatureSpotlight::for($key, $owner)['state'] === 'new') {
            $row['is_new'] = true;
            $row['feature_key'] = $key;
        }

        return $row;
    };

    // P1-1: 5-tab IA (direct cutover) — Workspace · Menu & Content · Grow · Money · Setup.
    // Every item builder is re-slotted verbatim (gates/patterns preserved); each visible
    // group is chunked to <=7 items. NavActiveStateTest freezes the full route contract.
    $tabs = [
        ['key' => 'workspace', 'title' => ___('Workspace'), 'rail' => 'fi-rr-apps', 'groups' => [
            [___('Overview'), array_filter([
                $link(route('dashboard'), ___('Overview'), 'fi-rr-dashboard', $on('dashboard')),
            ])],
            [___('Service'), array_filter([
                $it('stores.orders', ___('Orders'), 'fi-rr-receipt', ['stores.orders', 'orders.split.*']),
                /* MONETIZATION B2/B3: these route groups now carry
                   feature:<key>, so the sidebar MUST gate too or it advertises
                   links that 403 on click after V0 (NavFeatureGateTest). */
                $gatedIt('kds', 'kds.index', ___('Kitchen (KDS)'), 'fi-rr-hat-chef', ['kds.*', 'stations.*']),
                $gatedIt('reservations', 'reservations.index', ___('Reservations'), 'fi-rr-calendar-check', 'reservations.*'),
                // P3-10: appointment bookings (staff x service x time) for stores that sell services.
                $rid && app(VerticalRegistry::class)->has($ctxStore->store_type ?? null, 'service')
                    ? $it('bookings.owner.index', ___('Bookings'), 'fi-rr-calendar-check', 'bookings.owner.*', true, ['store_id' => $rid]) : null,
                $rid ? $gatedIt('waitlist', 'waitlist.index', ___('Waitlist'), 'fi-rr-clock', 'waitlist.*', [$rid]) : null,
                $rid ? $gatedIt('floor_map', 'floor-map.index', ___('Floor map'), 'fi-rr-grid', 'floor-map.*', [$rid]) : null,
                $gatedIt('waiter_mode', 'stores.waiter.index', ___('Waiter mode'), 'fi-rr-room-service', 'stores.waiter.*'),
            ])],
            [___('Fulfilment'), array_filter([
                /* P0-6 (feature-platform) upsell pilot: POS is plan-gated
                   (plan_feature('pos_enabled') — PosController 403s without it), so an
                   unentitled owner gets a LOCKED item linking to the upsell page
                   instead of a link that 403s on click. */
                ! EnsureStaffDashboardPermission::staffMayOpen('pos.index') ? null : ((! $owner || plan_feature('pos_enabled', $owner) || ! Route::has('feature.locked'))
                    ? $link($posUrl, ___('POS register'), 'fi-rr-calculator')
                    : $link(route('feature.locked', 'pos_enabled'), ___('POS register'), 'fi-rr-lock', $on('feature.locked'))),
                /* F3-8: dispatch.index is courier_dispatch; only the map/assign/
                   report sub-routes need driver_app — so the ITEM stays on
                   courier_dispatch and the split is enforced on the tabs below
                   (juwri_feature_tabs('dispatch')), which used to advertise a
                   Dispatch-map tab that 403s. */
                $gatedIt('courier_dispatch', 'stores.dispatch.index', ___('Dispatch'), 'fi-rr-route', 'stores.dispatch.*'),
                $gatedIt('catering', 'stores.catering.index', ___('Catering'), 'fi-rr-utensils', ['stores.catering.index', 'stores.catering.calendar']),
                $gatedIt('food_safety', 'stores.foodsafety.index', ___('Food safety'), 'fi-rr-thermometer-half', 'stores.foodsafety.*'),
                /* Moved out of Setup -> "Automation & API": both are delivery
                   OPERATIONS and belong beside Dispatch, not with the API keys. */
                $it('drivers.index', ___('Delivery drivers'), 'fi-rr-truck-side', 'drivers.*'),
                /* F3-6: driver_app gates the live dispatcher map/report, which had
                   NO nav entry at all — even entitled owners could not reach it. */
                $gatedIt('driver_app', 'stores.dispatch.map', ___('Dispatcher map'), 'fi-rr-map-marker-home', ['stores.dispatch.map', 'stores.dispatch.report']),
            ])],
        ]],
        /* Hotel gets its OWN rail/workspace tab so every PMS surface lives under a
           single dedicated destination instead of a group buried in Workspace. The
           lone group is labelled 'Hotel' to match the tab title, so the sidebar drops
           the redundant heading (nav-sidebar.blade: $groupLabel !== $t['title']); and
           an all-empty tab (hotel off, no rid) is filtered out by the tab array_filter
           at the end of this builder — the rail never shows for a non-hotel store. */
        ['key' => 'hotel', 'title' => ___('Hotel'), 'rail' => 'fi-rr-bed', 'groups' => [
            [___('Hotel'), (function () use ($it, $link, $on, $hotelOn, $hotelAvail, $rid) {
                $items = array_filter([
                    // WI-H1 + HP-B1: the nav must never advertise a link that 403s (same
                    // contract as $gatedIt). Post-HP the hotel dashboard is permission-scoped:
                    // rooms/info require `hotel_admin`, requests requires `hotel_requests`,
                    // stays requires `hotel_folio`. dashboard_allows() is true for the owner.
                    $it('hotel.rooms', ___('Rooms & areas'), 'fi-rr-bed', 'hotel.rooms*', $hotelOn && dashboard_allows('hotel_admin')),
                    // HPMS-B1: the booking-engine (PMS) surfaces. Every entry is
                    // Route::has()-guarded inside $it(), so the whole block stays
                    // invisible until lane A ships routes/features4/hotel-pms.php —
                    // nav renders pre-merge with no dead links. Permission scoping
                    // mirrors the strict-tier route perms (§5): bookings/calendar =
                    // hotel_folio, room-types/rates + services/events = hotel_admin,
                    // housekeeping = hotel_requests. A link the actor can't reach is
                    // never advertised (dashboard_allows) — same contract as $gatedIt.
                    $it('hotel.pms.bookings', ___('Bookings'), 'fi-rr-calendar', 'hotel.pms.bookings*', $hotelOn && dashboard_allows('hotel_folio')),
                    $it('hotel.pms.calendar', ___('Calendar'), 'fi-rr-calendar-lines', 'hotel.pms.calendar*', $hotelOn && dashboard_allows('hotel_folio')),
                    $it('hotel.stays', ___('Stays & folios'), 'fi-rr-file-invoice', 'hotel.stays*', $hotelOn && dashboard_allows('hotel_folio')),
                    $it('hotel.pms.housekeeping', ___('Housekeeping'), 'fi-rr-broom', 'hotel.pms.housekeeping*', $hotelOn && dashboard_allows('hotel_requests')),
                    $it('hotel.requests', ___('Service requests'), 'fi-rr-concierge-bell', 'hotel.requests*', $hotelOn && dashboard_allows('hotel_requests')),
                    // The index routes are named `hotel.pms.types` / `hotel.pms.services`
                    // (no `.index` suffix); the old `.index` names failed Route::has() so
                    // both items were silently dropped. Active patterns use a bare `*` glob
                    // so they match the index route AND its store/bulk/show sub-routes.
                    $it('hotel.pms.types', ___('Room types & rates'), 'fi-rr-bed-alt', ['hotel.pms.types*', 'hotel.pms.plans*', 'hotel.pms.rates*'], $hotelOn && dashboard_allows('hotel_admin')),
                    $it('hotel.pms.services', ___('Services & events'), 'fi-rr-cocktail', ['hotel.pms.services*', 'hotel.pms.events*'], $hotelOn && dashboard_allows('hotel_admin')),
                    $it('hotel.info', ___('Hotel settings'), 'fi-rr-info', 'hotel.info*', $hotelAvail && dashboard_allows('hotel_admin')),
                ]);
                /* P0-6 (feature-platform) upsell pilot: plan lacks hotel → the group
                   used to vanish silently. Show a LOCKED item linking to the upsell
                   page instead — unless the platform kill-switch turned hotel off. */
                $killed = config()->has('settings.admin_allow_hotel') && ! config('settings.admin_allow_hotel');
                if (! $items && $rid && ! $killed && Route::has('feature.locked')) {
                    $items = [$link(route('feature.locked', 'hotel'), ___('Hotel (upgrade)'), 'fi-rr-lock', $on('feature.locked'))];
                }

                return $items;
            })()],
        ]],
        ['key' => 'content', 'title' => ___('Menu & Content'), 'rail' => 'fi-rr-notebook', 'groups' => [
            [___('Catalog'), array_filter([
                $it('stores.index', ___('Stores'), 'fi-rr-utensils', ['stores.index', 'stores.create', 'stores.store', 'stores.show', 'stores.edit', 'stores.update', 'stores.destroy', 'stores.clone', 'stores.menu', 'stores.qrDesigner', 'stores.tables', 'stores.xml', 'stores.heartbeat'], true),
                /* Task 3: the public AI Menu Studio, surfaced for logged-in OWNERS
                   (publish is owner-only — staff get a 403 — so hide it for staff
                   sessions). Lives outside the dashboard route group, so the
                   active-state highlight is cosmetic-only. */
                staff_session() ? null : $it('studio.app.shell', ___('AI Menu Studio'), 'fi-rr-magic-wand', 'studio.app.shell'),
                /* MONETIZATION B1: the /inventory group now carries
                   `feature:inventory`, so the sidebar MUST gate the link too or
                   it advertises a destination that 403s on click once V0 flips
                   (the WIRING-RUN A1 / NavFeatureGateTest contract). */
                $gatedIt('inventory', 'inventory.index', ___('Inventory'), 'fi-rr-box', ['inventory.index', 'inventory.counts', 'inventory.ingredients', 'inventory.recipes', 'inventory.reports', 'inventory.suppliers', 'inventory.waste', 'inventory.cogs', 'inventory.purchaseOrders.*']),
                $it('modifiers.index', ___('Modifier library'), 'fi-rr-settings-sliders', 'modifiers.*'),
                /* PARITY-RUN T5: happy hours & day-part pricing. `feature:menu_schedules`
                   is on the route, so it MUST be gated here too or the sidebar
                   advertises a link that 403s on click (WIRING-RUN A1 contract). */
                $gatedIt('menu_schedules', 'stores.schedules.index', ___('Schedules'), 'fi-rr-clock', 'stores.schedules.*'),
                /* PARITY-RUN T6: `feature:combos` sits on the /combos route group, so
                   the sidebar MUST gate too or it advertises a link that 403s on
                   click (the WIRING-RUN A1 contract). */
                $gatedIt('combos', 'combos.index', ___('Combo meals'), 'fi-rr-box-alt', 'combos.*'),
                /* F3-6: supplier_suite (COGS + purchasing) is sold as an app and
                   on Premium, but was reachable only from a sub-tab inside
                   Inventory — entitled owners had no nav path to it. */
                $gatedIt('supplier_suite', 'inventory.cogs', ___('COGS & purchasing'), 'fi-rr-chart-pie-alt', ['inventory.cogs', 'inventory.purchaseOrders.*']),
                // WIRING-RUN A6: reachable only from deep inside the menu editor.
                $rid ? $it('stores.translations.review', ___('Translation review'), 'fi-rr-language', 'stores.translations.*', true, [$rid]) : null,
            ])],
            [___('Media'), array_filter([
                $it('media.index', ___('Media library'), 'fi-rr-images', 'media.*'),
                /* MONETIZATION B6: banners + stories route groups carry
                   feature:<key>, so gate the nav (NavFeatureGateTest). */
                $gatedIt('banners', 'banners.index', ___('Banners'), 'fi-rr-picture', 'banners.*'),
                $gatedIt('stories', 'stories.index', ___('Stories'), 'fi-rr-play-alt', 'stories.*'),
                $gatedIt('digital_signage', 'stores.signage.index', ___('Signage'), 'fi-rr-screen', 'stores.signage.*'),
            ])],
            [___('Design'), array_filter([
                $it('theme.customizer.page', ___('Theme customizer'), 'fi-rr-palette', 'theme.customizer.*'),
                // Theme Studio C1: `feature:theme_studio` is on the route group (ships
                // dark), so it is gated here too — hidden while killed, locked upsell
                // for an unentitled plan (WIRING-RUN A1 contract).
                $gatedIt('theme_studio', 'theme.studio', ___('Theme Studio'), 'fi-rr-layout-fluid', 'theme.studio*'),
                // Theme Studio phase A: owner-designed Builder.js blocks
                // (`feature:custom_sections` on the route group, ships dark).
                $gatedIt('custom_sections', 'custom-blocks.index', ___('Custom blocks'), 'fi-rr-apps-add', 'custom-blocks.*'),
                // PARITY-RUN T10: the QR design suite. `feature:qr_designer` is on the
                // route, so it MUST be gated here too or the sidebar advertises a link
                // that 403s on click (WIRING-RUN A1 contract).
                $gatedIt('qr_designer', 'qr.designer', ___('QR Designer'), 'fi-rr-qrcode', 'qr.designer*'),
                $gatedIt('kiosk', 'stores.kiosk.index', ___('Kiosk'), 'fi-rr-tablet', 'stores.kiosk.*'),
                /* BIOLINK-RUN (Lane B): the Linktree-class bio page. `feature:biolink`
                   is on the route group, so it MUST be gated here too or the sidebar
                   advertises a link that 403s (WIRING-RUN A1 contract). Wrapped in
                   dashboard_allows('biolink') so a staff member WITHOUT the biolink
                   permission never sees it (EnsureStaffDashboardPermission 403s the
                   click regardless — this keeps the nav honest). */
                dashboard_allows('biolink') ? $gatedIt('biolink', 'biolink.index', ___('Bio page'), 'fi-rr-link', 'biolink.*') : null,
            ])],
            /* Where the menu is served from. Moved out of Setup: both answer
               "where does my content appear", not "how is the account run". */
            [___('Locations'), array_filter([
                $it('branches.index', ___('Branches'), 'fi-rr-marker', 'branches.*'),
                // WIRING-RUN A2: custom domains had a complete backend and no owner UI at all.
                // MONETIZATION B7: custom domains now gated — gate the nav too.
                $rid ? $gatedIt('custom_domain', 'stores.domains.index', ___('Custom domain'), 'fi-rr-globe-alt', 'stores.domains.*', [$rid]) : null,
            ])],
        ]],
        ['key' => 'grow', 'title' => ___('Grow'), 'rail' => 'fi-rr-chart-line-up', 'groups' => [
            [___('Marketing'), array_filter([
                $it('campaigns.index', ___('Campaigns'), 'fi-rr-megaphone', 'campaigns.*'),
                $gatedIt('marketing_automation', 'stores.automations.index', ___('Automations'), 'fi-rr-bolt', 'stores.automations.*'),
                /* P1-17 (J27-B): AI social post drafts — same rule as the other content AI tools (AI credits), no feature key. */
                $it('stores.marketing.socialPost.index', ___('Social post writer'), 'fi-rr-magic-wand', 'stores.marketing.socialPost.*'),
                $it('stores.coupons.index', ___('Coupons'), 'fi-rr-ticket', 'stores.coupons.*'),
                $gatedIt('gift_cards', 'stores.giftcards.index', ___('Gift cards'), 'fi-rr-gift', 'stores.giftcards.*'),
                /* MONETIZATION B6: loyalty + referral now gated. */
                $rid ? $gatedIt('loyalty', 'loyalty.settings', ___('Loyalty'), 'fi-rr-heart', 'loyalty.*', [$rid]) : null,
                $gatedIt('referrals', 'referral.index', ___('Referrals'), 'fi-rr-share', 'referral.*'),
            ])],
            // WIRING-RUN A4: a full customer-messaging channel that was reachable only
            // from a link buried on the store page — never from the nav or the search palette.
            [___('WhatsApp'), array_filter([
                $rid ? $it('stores.whatsappInbox', ___('WhatsApp inbox'), 'fi-rr-comment-dots', 'stores.whatsappInbox*', true, [$rid]) : null,
                $rid ? $it('stores.whatsappCampaign', ___('WhatsApp campaign'), 'fi-rr-paper-plane', 'stores.whatsappCampaign*', true, [$rid]) : null,
                $rid ? $it('stores.whatsappTemplates', ___('WhatsApp templates'), 'fi-rr-copy-alt', 'stores.whatsappTemplates*', true, [$rid]) : null,
                $rid ? $it('stores.whatsappSettings', ___('WhatsApp settings'), 'fi-brands-whatsapp', 'stores.whatsappSettings*', true, [$rid]) : null,
            ])],
            [___('Customers'), array_filter([
                $it('customers.index', ___('Customers'), 'fi-rr-users', 'customers.*'),
                // HPMS E9: merge-duplicate-customers owner tool. Owner-only (the route
                // is default-deny for staff), so hide it from staff to avoid a 403 link.
                $it('customers.merge.index', ___('Merge duplicates'), 'fi-rr-duplicate', 'customers.merge.index', Route::has('customers.merge.index') && ! staff_session()),
                /* One lock entry per feature: the primary item carries the upsell,
                   sibling items of the same key stay hidden until entitled. */
                $gatedIt('crm_suite', 'crm.rfm', ___('Guest CRM (RFM)'), 'fi-rr-chart-histogram', ['crm.rfm', 'crm.profile', 'crm.preferences', 'crm.retag']),
                $it('crm.audiences', ___('Audiences'), 'fi-rr-filter', 'crm.audiences', $feat('crm_suite')),
                $rid ? $it('dashboard.reviews', ___('Reviews'), 'fi-rr-star', 'dashboard.reviews*', true, [$rid], $pendingReviews) : null,
                $rid ? $it('dashboard.store-reviews', ___('Directory reviews'), 'fi-rr-comment-check', 'dashboard.store-reviews*', $dirReviewsOn, [$rid]) : null,
                $gatedIt('support_desk', 'stores.feedback.index', ___('Feedback'), 'fi-rr-comment-heart', 'stores.feedback.*'),
            ])],
            [___('Channels & insights'), array_filter([
                $it('analytics.index', ___('Analytics'), 'fi-rr-chart-line-up', 'analytics.*'),
                $it('reports.index', ___('Reports'), 'fi-rr-document', 'reports.index'),
                $gatedIt('demand_forecast', 'stores.deliveryInsights.index', ___('Delivery insights'), 'fi-rr-signal-alt', 'stores.deliveryInsights.*'),
            ])],
            /* DIRECTORY L4 (T4-3): visible only when the module is on AND this
               store has some directory presence (a claimed listing, or its own
               Fusion marketplace opt-in) — a store with neither gets no link,
               since there is nothing yet to manage. This is a PRESENCE gate,
               not a paid-tier one: directory_pro/directory_featured stay
               server-checked inside the controller (D13), never here. */
            [___('Directory'), array_filter([
                ($rid && app(Directory::class)->navVisible((int) $rid, $ctxStore instanceof Store ? $ctxStore : null))
                    ? $it('stores.directory.index', ___('Directory'), 'fi-rr-marker', 'stores.directory.*') : null,
            ])],
        ]],
        ['key' => 'money', 'title' => ___('Money'), 'rail' => 'fi-rr-coins', 'groups' => [
            [___('Billing'), array_filter([
                $it('wallet.index', ___('Wallet'), 'fi-rr-wallet', 'wallet.*'),
                // Add-ons moved to the "Apps & Add-ons" tab (still hidden under a
                // reseller brand — it is a platform storefront).
                // WIRING-RUN A5: billing history was linked only from the wire-transfer
                // gateway page and the subscription screen — never from the nav.
                $it('transactions', ___('Billing history'), 'fi-rr-time-past', 'transactions'),
            ])],
            [___('Accounting'), array_filter([
                $gatedIt('accounting_gl', 'stores.books.index', ___('Accounting'), 'fi-rr-calculator', ['stores.books.index', 'stores.books.journals', 'stores.books.expenses', 'stores.books.reports', 'stores.books.period']),
                $it('stores.accounting.index', ___('Accounting sync'), 'fi-rr-refresh', 'stores.accounting.*', $feat('accounting_sync')),
            ])],
            [___('Payroll'), array_filter([
                $gatedIt('payroll_suite', 'stores.payroll.index', ___('Payroll'), 'fi-rr-money-check-edit', ['stores.payroll.index', 'stores.payroll.show', 'stores.payroll.generate', 'stores.payroll.submit', 'stores.payroll.approve', 'stores.payroll.pays', 'stores.payroll.pool', 'stores.payroll.bank', 'stores.payroll.adjust', 'stores.payroll.mark', 'stores.payroll.labor']),
            ])],
            [___('Compliance'), array_filter([
                /* FIX-3 F3-3: was gated on the kill-switch ALONE, so every
                   unentitled owner saw a Fiscalization link that 403s (the key
                   became plan_gated with the Premium bundle). Tri-state now. */
                $gatedIt('fiscalization', 'fiscal.index', ___('Fiscalization'), 'fi-rr-badge-check', 'fiscal.*'),
                $it('stores.disputes.index', ___('Disputes'), 'fi-rr-shield-exclamation', 'stores.disputes.*', $feat('disputes')),
            ])],
        ]],
        /* Everything a merchant can add on top of their plan, in one place.
           F7-5: the platform storefronts carry OUR branding and OUR billing, so
           they stay hidden under a reseller brand; "My apps" and "My add-ons"
           only manage what the merchant already has, so they always show. */
        ['key' => 'store', 'title' => ___('Apps & Add-ons'), 'rail' => 'fi-rr-shop', 'groups' => [
            [___('Apps'), array_filter([
                $whiteLabel ? null : $it('user.store.apps', ___('Browse apps'), 'fi-rr-apps-add', 'user.store.apps*'),
                $it('apps.page', ___('My apps'), 'fi-rr-apps', 'apps.page'),
            ])],
            [___('Themes'), array_filter([
                $whiteLabel ? null : $it('user.store.themes', ___('Browse themes'), 'fi-rr-swatchbook', 'user.store.themes*'),
                /* The theme CUSTOMIZER stays under Menu & Content → Design: it edits
                   the theme you already run, it does not sell one. Listing it twice
                   would give the same route two active nav entries. */
            ])],
            [___('Add-ons'), array_filter([
                $whiteLabel ? null : $it('addons.index', ___('Add-ons'), 'fi-rr-plus', 'addons.*'),
                // Credit packs (PRICING.md §8): prepaid AI / SMS / WhatsApp credits — a platform storefront like add-ons.
                $whiteLabel ? null : $it('credits.index', ___('Prepaid credits'), 'fi-rr-coins', 'credits.*'),
                $it('subscription', ___('Plan & subscription'), 'fi-rr-badge', 'subscription'),
            ])],
        ]],
        /* Integrations tab: everything that connects the platform to something
           OUTSIDE it — sales channels, POS/hardware, and the API surface. These
           were split across Setup ("Integrations" + "Automation & API") and Grow
           ("Google ordering"), so there was no single place to see what is
           connected. Grouped by what the merchant is connecting TO. */
        ['key' => 'integrations', 'title' => ___('Integrations'), 'rail' => 'fi-rr-bolt', 'groups' => [
            [___('Overview'), array_filter([
                $it('settings.integrations', ___('All integrations'), 'fi-rr-bolt', 'settings.integrations*'),
            ])],
            [___('Sales channels'), array_filter([
                $gatedIt('gbp_link', 'stores.gbp.index', ___('Google ordering'), 'fi-rr-globe', 'stores.gbp.*'),
                $it('stores.aggregators.index', ___('Delivery channels'), 'fi-rr-shuffle', 'stores.aggregators.*', $feat('aggregator_hub')),
                // MONETIZATION C3: HungerStation now gates on aggregator_hub, so
                // gate its nav too (matching the Delivery channels row above).
                $gatedIt('aggregator_hub', 'hungerstation.page', ___('HungerStation'), 'fi-rr-bike', 'hungerstation.*'),
                /* PARITY-RUN T7: the India marketplaces. Store-scoped routes, so they
                   only appear once a store is in context — same shape as Custom domain. */
                $rid ? $gatedIt('aggregator_zomato', 'stores.zomato.index', 'Zomato', 'fi-rr-hamburger', 'stores.zomato.*', [$rid]) : null,
                $rid ? $gatedIt('aggregator_swiggy', 'stores.swiggy.index', 'Swiggy', 'fi-rr-moped', 'stores.swiggy.*', [$rid]) : null,
                // BYOK courier accounts (Uber Direct / DoorDash) — same gate as Dispatch.
                $gatedIt('courier_dispatch', 'stores.dispatch.couriers', ___('Courier accounts'), 'fi-rr-key', 'stores.dispatch.couriers*'),
                /* WA-C3: WhatsApp ordering used to be a QuickOrder plugin page with no
                   inbound link. It now lives on the core WhatsApp settings page, so the
                   nav points there and no longer depends on the plugin being installed. */
                $rid ? $it('stores.whatsappSettings', ___('WhatsApp ordering'), 'fi-rr-comment-alt', 'stores.whatsappSettings', true, [$rid]) : null,
            ])],
            [___('POS & hardware'), array_filter([
                $it('stores.posbridge.index', ___('POS bridge'), 'fi-rr-cash-register', 'stores.posbridge.*', $feat('pos_bridge')),
                $it('stores.foodics.index', 'Foodics POS', 'fi-rr-computer', 'stores.foodics.*', $feat('pos_bridge')),
                $it('stores.marn.index', 'Marn POS', 'fi-rr-devices', 'stores.marn.*', $feat('pos_bridge')),
                // P5-37: owner SoftPOS (Geidea / EdfaPay) account page.
                $it('softpos.index', ___('Card terminals'), 'fi-rr-credit-card', 'softpos.*', true),
                // PARITY-RUN T1: printers & routing (Web Serial/USB, RawBT, LAN bridge).
                $gatedIt('print_routing', 'stores.printing.index', ___('Printing'), 'fi-rr-print', 'stores.printing.*'),
                // PARITY-RUN T9: mobile app & push (Firebase BYOK, device list).
                $gatedIt('mobile_app_push', 'stores.mobilepush.index', ___('Mobile App & Push'), 'fi-rr-mobile-notch', 'stores.mobilepush.*'),
            ])],
            [___('Automation & API'), array_filter([
                // D1 (integrations pack): the Integrations hub. gatedIt handles the
                // tri-state feature:integrations gate (link / upsell / hidden).
                $gatedIt('integrations', 'integrations.index', ___('Integrations'), 'fi-rr-apps-add', 'integrations.*'),
                $it('api.index', ___('API & Webhooks'), 'fi-rr-code-simple', 'api.*'),
                $it('connect-ai', ___('Connect AI'), 'fi-rr-sparkles', 'connect-ai'),
                $it('agent.settings.page', ___('AI ordering'), 'fi-rr-comments', 'agent.settings.*'),
                // P6-09: Phone agent settings + its call log (same `ai_phone_agent` gate as the routes).
                $gatedIt('ai_phone_agent', 'stores.phoneagent.index', ___('Phone agent'), 'fi-rr-phone-call', 'stores.phoneagent.index'),
                $gatedIt('ai_phone_agent', 'stores.phoneagent.calls.index', ___('Call log'), 'fi-rr-time-past', ['stores.phoneagent.calls.*', 'stores.phoneagent.call']),
                /* C5 (hotel-pms) — AI menu actions, gated on the same ai_assistant SKU as the
                   dashboard assistant; Route::has-guarded, owner-only (no perm-map entry). */
                $gatedIt('ai_assistant', 'ai.menu-actions', ___('AI menu actions'), 'fi-rr-magic-wand', 'ai.menu-actions*'),
            ])],
            [___('Import'), array_filter([
                $gatedIt('gloriafood_import', 'stores.import.gloriafood', ___('Import from GloriaFood'), 'fi-rr-download', 'stores.import.*'),
            ])],
        ]],
        ['key' => 'setup', 'title' => ___('Setup'), 'rail' => 'fi-rr-settings', 'groups' => [
            [___('Team'), array_filter([
                $it('staff.index', ___('Staff'), 'fi-rr-users', 'staff.*'),
                $gatedIt('team_comms', 'stores.team.index', ___('Team inbox'), 'fi-rr-comment-alt', ['stores.team.index', 'stores.team.channels', 'stores.team.thread', 'stores.team.notes']),
                $it('stores.team.checklists', ___('Checklists'), 'fi-rr-list-check', 'stores.team.checklists', $feat('team_comms')),
                $gatedIt('staff_rota', 'stores.rota.index', ___('Rota'), 'fi-rr-calendar-clock', 'stores.rota.*'),
                $gatedIt('hrm_suite', 'stores.leave.index', ___('Leave'), 'fi-rr-plane-departure', 'stores.leave.*'),
                $it('stores.rotaplus.index', ___('Open shifts & swaps'), 'fi-rr-arrows-repeat', 'stores.rotaplus.*', $feat('team_comms')),
                $it('activity-log.index', ___('Activity log'), 'fi-rr-pulse', 'activity-log.*'),
                // reseller-store R4-2 (O2) [Lane R4 additive]: the owner's read-only
                // transparency feed of reseller-made store changes. Only meaningful
                // to a customer sitting under a reseller, so gate on that.
                $it('reseller-managed.index', ___('Managed changes'), 'fi-rr-eye', 'reseller-managed.*', (bool) ($owner?->reseller_id)),
            ])],
            /* Apps / themes / add-ons moved to their own "Apps & Add-ons" tab —
               they were split across Setup (storefronts) and Money (add-ons), so
               there was no single place to see what you can bolt onto the plan.
               F3-6 note: multivendor_cart deliberately gets NO owner nav item.
               Its browse UI retired into the public Fusion marketplace (/fusion,
               mk.* routes) and its console is superadmin-only (admin.fusion.*). */
            [___('Account'), array_filter([
                ! EnsureStaffDashboardPermission::staffMayOpen('settings') ? null : $link($settingsUrl, ___('Settings'), 'fi-rr-settings', request()->routeIs('settings')),
                // HPMS E14(d): extra storefront pages (FAQ / refund / delivery). Owner-only.
                $it('storefront-pages.edit', ___('Storefront pages'), 'fi-rr-document', 'storefront-pages.*', Route::has('storefront-pages.edit') && ! staff_session()),
                $it('platform-catalog.index', ___('Menu library'), 'fi-rr-books', 'platform-catalog.*'),
                $it('backup.index', ___('Backup & export'), 'fi-rr-cloud-download', 'backup.*'),
                $it('transfer.index', ___('Ownership transfer'), 'fi-rr-exchange', 'transfer.*'),
                // DOCS-PLATFORM D1-4: knowledge base — host-branded (/help-center
                // renders under the request's brand), so no white-label gate needed.
                $it('help.index', ___('Help Center'), 'fi-rr-book-alt', 'help.*'),
                $gatedIt('support_desk', 'support.index', ___('Help & support'), 'fi-rr-headset', 'support.*'),
                $ownsReseller ? $it('reseller.portal', ___('Reseller portal'), 'fi-rr-building', 'reseller.*') : null,
                $isAdmin ? $it('admin.dashboard', ___('Admin panel'), 'fi-rr-shield', 'admin*') : null,
            ])],
        ]],
    ];

    /* Drop any tab whose every group came back empty. Gating can empty a whole
       tab (a white-label brand hides the platform storefronts, for instance),
       and the sidebar renders a rail icon per tab regardless — so without this
       the rail could show a tab that opens onto nothing. */
    $tabs = array_values(array_filter($tabs, function ($tab) {
        foreach ($tab['groups'] as [$label, $items]) {
            if (count($items)) {
                return true;
            }
        }

        return false;
    }));

    $__bucket = $cache[$__req] ?? [];
    $__bucket[$__ck] = $tabs;
    $cache[$__req] = $__bucket;

    return $tabs;
}

/**
 * Flat searchable inventory built from juwri_nav_model() — the topbar search
 * palette source. Each entry: label, url, icon, group (for context).
 */
function dashboard_nav_search(): array
{
    $out = [];
    foreach (juwri_nav_model() as $tab) {
        foreach ($tab['groups'] as [$groupLabel, $items]) {
            foreach ($items as $i) {
                if (empty($i['url']) || empty($i['label'])) {
                    continue;
                }
                $out[] = [
                    'label' => (string) $i['label'],
                    'url' => (string) $i['url'],
                    'icon' => $i['icon'] ?? 'fi-rr-angle-right',
                    'group' => (string) ($tab['title'] ?? ''),
                ];
            }
        }
    }
    // De-dup by url (Overview/Settings appear once).
    $seen = [];

    return array_values(array_filter($out, function ($e) use (&$seen) {
        if (isset($seen[$e['url']])) {
            return false;
        }
        $seen[$e['url']] = true;

        return true;
    }));
}

/**
 * P1-2: server-side "in-page tab" sets for feature clusters that used to be
 * separate nav rows. Each returns [[label,url,active], ...] with active driven
 * by the current route so the pages stay separate but read as one feature.
 * Gated so a missing route just drops that tab.
 */
function juwri_feature_tabs(string $cluster): array
{
    $on = fn ($p) => request()->routeIs($p);
    /* FIX-3 F3-7: a tab may need a feature the owner doesn't have (the COGS tab
       sat on an ungated Inventory page and linked into feature:supplier_suite;
       the Dispatch-map tab needs driver_app while its page needs only
       courier_dispatch). Gated tabs are dropped rather than advertising a 403. */
    $tabAllows = function (?string $key): bool {
        if ($key === null) {
            return true;
        }
        try {
            if (! FeatureRegistry::isEnabled($key)) {
                return false;
            }
            if (! EnsureFeatureEnabled::planGated($key)) {
                return true;
            }
            $owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();

            return $owner && Entitlements::allows($owner, $key);
        } catch (Throwable $e) {
            return false;
        }
    };
    $t = function ($route, $label, $pattern = null, $params = [], ?string $featureKey = null) use ($on, $tabAllows) {
        if (! Route::has($route)) {
            return null;
        }
        if (! $tabAllows($featureKey)) {
            return null;
        }

        return ['label' => $label, 'url' => route($route, $params), 'active' => $on($pattern ?? $route)];
    };

    $sets = [
        'dispatch' => [
            $t('stores.dispatch.index', ___('Dispatch'), 'stores.dispatch.index'),
            $t('stores.dispatch.map', ___('Dispatch map'), 'stores.dispatch.map', [], 'driver_app'),
            $t('stores.dispatch.couriers', ___('Courier accounts'), 'stores.dispatch.couriers*', [], 'courier_dispatch'),
        ],
        'catering' => [
            $t('stores.catering.index', ___('Catering'), 'stores.catering.index'),
            $t('stores.catering.calendar', ___('Catering calendar'), 'stores.catering.calendar'),
        ],
        'payroll' => [
            $t('stores.payroll.index', ___('Payroll'), ['stores.payroll.index', 'stores.payroll.show', 'stores.payroll.generate', 'stores.payroll.submit', 'stores.payroll.approve', 'stores.payroll.pays', 'stores.payroll.pool', 'stores.payroll.bank', 'stores.payroll.adjust', 'stores.payroll.mark']),
            $t('stores.payroll.labor', ___('Labor cost'), 'stores.payroll.labor'),
        ],
        'inventory' => [
            // MONETIZATION B1: mirror the sidebar gate — the /inventory group is
            // now feature:inventory, so the search palette must gate it too.
            $t('inventory.index', ___('Inventory'), ['inventory.index', 'inventory.counts', 'inventory.ingredients', 'inventory.recipes', 'inventory.reports', 'inventory.suppliers', 'inventory.waste'], [], 'inventory'),
            $t('inventory.cogs', ___('COGS & purchasing'), ['inventory.cogs', 'inventory.purchaseOrders.*'], [], 'supplier_suite'),
        ],
        'kds' => [
            $t('kds.index', ___('Kitchen (KDS)'), 'kds.*', [], 'kds'), // MONETIZATION B2: gate palette entry
            $t('stations.index', ___('Kitchen stations'), 'stations.*'),
        ],
        'accounting' => [
            $t('stores.books.index', ___('Accounting'), 'stores.books.index'),
            $t('stores.books.journals', ___('Journals'), 'stores.books.journals'),
            $t('stores.books.expenses', ___('Expenses'), 'stores.books.expenses'),
            $t('stores.books.reports', ___('Financial reports'), 'stores.books.reports'),
        ],
    ];

    return array_values(array_filter($sets[$cluster] ?? []));
}

/**
 * P1-4: single period model shared by the dashboard and analytics.
 * Presets: today | 7d | 30d | month | custom. Persists the last choice in the
 * session (dashboard_period + from/to). Compare window defaults to the SAME
 * period one week earlier (weekday-aligned) so deltas read "vs last week".
 * Returns [period, compare, start, end, prevStart, prevEnd, label, from, to].
 */
function resolve_dashboard_period(): array
{
    $valid = ['today', '7d', '30d', 'month', 'custom'];

    // Explicit request wins and is remembered; otherwise fall back to the session.
    if (request()->has('period')) {
        $period = (string) request('period');
        if (! in_array($period, $valid, true)) {
            $period = 'month';
        }
        session(['dashboard_period' => $period]);
        if ($period === 'custom') {
            session(['dashboard_period_from' => request('from'), 'dashboard_period_to' => request('to')]);
        }
    } else {
        $period = (string) session('dashboard_period', 'month');
        if (! in_array($period, $valid, true)) {
            $period = 'month';
        }
    }

    $compare = (string) request('compare', '1') !== '0';

    // Period boundaries follow the active store's timezone: "this month" for a
    // New York store must be its own month, not the platform's. The all-stores
    // view ($scope=all) and any non-dashboard caller fall back to the platform
    // timezone. created_at is persisted as an app-timezone wall-clock, so the
    // start/end instants are converted back to the app timezone before they are
    // returned for use as query bounds (see the setTimezone() below); the
    // human-facing from/to strings are captured in the store's timezone first.
    $appTz = config('app.timezone') ?: 'UTC';
    $scopeAll = request('scope') === 'all';
    $periodStoreId = (! $scopeAll && function_exists('dashboard_current_store'))
        ? dashboard_current_store()?->id : null;
    $tz = ($periodStoreId && function_exists('store_tz')) ? store_tz($periodStoreId) : $appTz;
    $now = Carbon::now($tz);

    switch ($period) {
        case 'today':
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
            break;
        case '7d':
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
            break;
        case '30d':
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
            break;
        case 'custom':
            $from = request()->has('period') ? request('from') : session('dashboard_period_from');
            $to = request()->has('period') ? request('to') : session('dashboard_period_to');
            try {
                $start = $from ? Carbon::parse($from, $tz)->startOfDay() : $now->copy()->startOfMonth();
            } catch (Throwable $e) {
                $start = $now->copy()->startOfMonth();
            }
            try {
                $end = $to ? Carbon::parse($to, $tz)->endOfDay() : $now->copy()->endOfDay();
            } catch (Throwable $e) {
                $end = $now->copy()->endOfDay();
            }
            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
            break;
        case 'month':
        default:
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfMonth();
            break;
    }

    // Capture the human-facing labels in the store's timezone, then normalise the
    // bounds to the app timezone so they line up with created_at when queried.
    $fromStr = $start->toDateString();
    $toStr = $end->toDateString();
    $start = $start->setTimezone($appTz);
    $end = $end->setTimezone($appTz);

    // Compare = the same window one week earlier, weekday-aligned.
    $prevStart = $start->copy()->subWeek();
    $prevEnd = $end->copy()->subWeek();

    $labels = [
        'today' => ___('Today'),
        '7d' => ___('Last 7 days'),
        '30d' => ___('Last 30 days'),
        'month' => ___('This month'),
        'custom' => ___('Custom range'),
    ];

    return [
        'period' => $period,
        'compare' => $compare,
        'start' => $start,
        'end' => $end,
        'prevStart' => $prevStart,
        'prevEnd' => $prevEnd,
        'label' => $labels[$period] ?? $labels['month'],
        'from' => $fromStr,
        'to' => $toStr,
    ];
}

/**
 * P2-3: report-hub catalog — report destinations grouped into categories.
 * Each entry: key, label, url, icon. Route-gated (missing feature → dropped).
 */
function juwri_report_catalog(): array
{
    $rid = (function_exists('dashboard_current_store') ? dashboard_current_store()?->id : null);
    $owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();

    /* FIX-3 F3-4: report links were gated on Route::has() ALONE, so the hub
       advertised half the paid suites (CRM, feedback, COGS, financials, labor,
       delivery insights) to owners whose plan 403s them on click. Same tri-state
       as the sidebar: entitled → the report, unentitled → its upsell page,
       killed → gone. */
    $state = function (?string $key) use ($owner): string {
        if ($key === null) {
            return 'ok';
        }
        try {
            if (! FeatureRegistry::isEnabled($key)) {
                return 'off';
            }
            if (! EnsureFeatureEnabled::planGated($key)) {
                return 'ok';
            }
            if ($owner && Entitlements::allows($owner, $key)) {
                return 'ok';
            }

            return app(FeatureCommerce::class)->sellable($key) ? 'locked' : 'off';
        } catch (Throwable $e) {
            return 'off';
        }
    };
    $r = function ($route, $label, $icon, ?string $featureKey = null) use ($rid, $state) {
        if (! Route::has($route)) {
            return null;
        }
        $st = $state($featureKey);
        if ($st === 'off') {
            return null;
        }
        if ($st === 'locked') {
            if (! Route::has('feature.locked')) {
                return null;
            }

            return ['key' => $route, 'label' => $label, 'url' => route('feature.locked', $featureKey), 'icon' => 'fi-rr-lock', 'locked' => true];
        }
        // Report routes may or may not need a store param; try without, then with.
        foreach ([[], $rid ? [$rid] : []] as $params) {
            try {
                return ['key' => $route, 'label' => $label, 'url' => route($route, $params), 'icon' => $icon, 'locked' => false];
            } catch (Throwable $e) {
            }
        }

        return null;
    };
    $cats = [
        ['title' => ___('Sales'), 'items' => array_filter([
            $r('analytics.index', ___('Scan & sales analytics'), 'fi-rr-chart-line-up'),
            $r('stores.books.reports', ___('Financial reports'), 'fi-rr-chart-pie', 'accounting_gl'),
        ])],
        ['title' => ___('Guests'), 'items' => array_filter([
            $r('crm.rfm', ___('Guest CRM (RFM)'), 'fi-rr-chart-histogram', 'crm_suite'),
            $r('customers.index', ___('Customers'), 'fi-rr-users'),
            $r('stores.feedback.index', ___('Feedback'), 'fi-rr-comment-heart', 'support_desk'),
        ])],
        ['title' => ___('Menu'), 'items' => array_filter([
            $r('inventory.reports', ___('Inventory reports'), 'fi-rr-box'),
            $r('inventory.cogs', ___('COGS & purchasing'), 'fi-rr-chart-pie-alt', 'supplier_suite'),
        ])],
        ['title' => ___('Delivery'), 'items' => array_filter([
            $r('stores.deliveryInsights.index', ___('Delivery insights'), 'fi-rr-signal-alt', 'demand_forecast'),
        ])],
        ['title' => ___('Labor'), 'items' => array_filter([
            $r('stores.payroll.labor', ___('Labor cost'), 'fi-rr-clock', 'payroll_suite'),
            $r('activity-log.index', ___('Activity log'), 'fi-rr-pulse'),
        ])],
        ['title' => ___('Finance'), 'items' => array_filter([
            $r('stores.books.journals', ___('Journals'), 'fi-rr-book', 'accounting_gl'),
            $r('stores.books.expenses', ___('Expenses'), 'fi-rr-receipt', 'accounting_gl'),
            $r('stores.disputes.index', ___('Disputes'), 'fi-rr-shield-exclamation', 'disputes'),
        ])],
    ];

    // Drop empty categories.
    return array_values(array_filter($cats, fn ($c) => count($c['items']) > 0));
}

/** P2-3: the current user's pinned destination keys (validated on read). */
function juwri_nav_pin_keys(): array
{
    $owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();
    if (! $owner) {
        return [];
    }
    $raw = UserOption::getUserOption($owner->id, 'nav_pins');
    $keys = $raw ? json_decode($raw, true) : [];

    return is_array($keys) ? array_values(array_filter($keys, fn ($k) => is_string($k) && Route::has($k))) : [];
}

/** P2-3: resolve pinned keys to renderable nav rows [{label,url,icon}] (max 6). */
function juwri_nav_pins(): array
{
    $out = [];
    foreach (juwri_nav_pin_keys() as $key) {
        // Prefer the report-catalog label/icon; fall back to a plain route link.
        $found = null;
        foreach (juwri_report_catalog() as $cat) {
            foreach ($cat['items'] as $it) {
                if ($it['key'] === $key) {
                    $found = $it;
                    break 2;
                }
            }
        }
        if ($found) {
            $out[] = ['label' => $found['label'], 'url' => $found['url'], 'icon' => $found['icon']];

            continue;
        }
        if (Route::has($key)) {
            $out[] = ['label' => Str::of($key)->afterLast('.')->replace(['-', '_'], ' ')->title(), 'url' => route($key), 'icon' => 'fi-rr-bookmark'];
        }
    }

    return array_slice($out, 0, 6);
}
