<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * stores P1-3: marketplace vocabularies. Categories/industries are config, not DB
 * (spec §0.4 — same pattern as studio aspects). Labels are translated in views via
 * ___('cafe') etc. Adding a value here is additive; existing rows keep their key.
 */
return [

    /* Fusion (customer delivery marketplace, rev/marketplace-app). Brand LOCKED
       by D6 — views must use config('marketplace.name'), never the literal. */
    /* Cache-buster for every /assets/marketplace file. SINGLE source of truth:
       child-view @section blocks render BEFORE the layout's @php, so a
       $mkAssetV defined there is never in scope in children — every blade's
       "?? 'NN'" fallback silently shipped stale JS (search was pinned at v26
       while the layout served v31). Bump HERE on every css/js edit and keep
       assets/marketplace/sw.js PRECACHE in sync. */
    'asset_v' => '45',

    'name' => env('MARKETPLACE_NAME', 'Fusion'),
    'name_ar' => env('MARKETPLACE_NAME_AR', 'فيوجن'),

    // Theme Store industries (themes.category). Order = display order in the filter rail.
    'theme_industries' => [
        'cafe',
        'fine_dining',
        'fast_food',
        'bakery',
        'pizza',
        'multi_branch',
        'dark_kitchen',
        'general',
    ],

    // App Store categories (apps.category).
    /* P5-7 (feature-platform): scopes that touch guest/order data — submitting
       them requires a privacy/docs URL + a written justification and gets a
       deeper human review. */
    'protected_scopes' => ['orders:read', 'orders:write', 'analytics:read'],

    'app_categories' => [
        'ordering',
        'operations',
        'marketing',
        'delivery',
        'analytics',
        'loyalty',
        'back_office',
        'pos',
        'integrations',
    ],

    // Media rules (marketplace_media). Enforced server-side on upload (P1-4 / P4).
    'media' => [
        'kinds'          => ['screenshot', 'banner', 'icon'],
        'disk'           => 'public',
        'max_screenshots' => 8,
        'max_kb'         => 4096,                       // 4 MB per file
        'mimes'          => ['jpg', 'jpeg', 'png', 'webp'],
        // Icons go through the raster image pipeline (Intervention) — no SVG.
        'icon_mimes'     => ['png', 'webp', 'jpg', 'jpeg'],
    ],

    // Review rules (marketplace_reviews). Enforced in validation + moderation.
    'review' => [
        'min_rating' => 1,
        'max_rating' => 5,
        'title_max'  => 120,
        'body_max'   => 2000,
    ],

    /* L3 T3-3: donation round-up (bag grand total → next whole unit), per
       country, OFF by default. Key = ISO2 country code (countries.code,
       uppercase). Unlisted countries fall back to 'default'. 'label' is the
       chip text — translated in views via ___($label). Phase B (platform
       custody) revisits where the money lands; for now it rides on one store
       order (see the T3-3 migration docblock). */
    'donation' => [
        'default' => ['enabled' => false, 'label' => 'Round up to donate'],
        'countries' => [
            // 'SA' => ['enabled' => true, 'label' => 'Round up to donate'],
        ],
    ],

    /* T4-2: fleet dispatch orchestration (App\Services\Marketplace\DispatchOrchestrator).
       offer_batch = how many nearest drivers get a simultaneous offer;
       offer_ttl_seconds = how long each offer stays acceptable before the
       cascade moves to the next-nearest driver. */
    'dispatch' => [
        'offer_batch' => (int) env('MK_DISPATCH_OFFER_BATCH', 1),
        'offer_ttl_seconds' => (int) env('MK_DISPATCH_OFFER_TTL', 60),

        /* T4-4: courier fallback. When an order's fleet offers have ALL died
           (expired/rejected, none pending) and N minutes have passed since the
           first offer, fallbackDueOrders() auto-escalates to a 3rd-party
           courier — but only for stores whose mk_delivery_modes includes
           'courier' (the store-level allow flag; a city-level kill switch is a
           T5-6 seam, not built here). */
        'courier_fallback_enabled' => (bool) env('MK_DISPATCH_COURIER_FALLBACK', true),
        'courier_fallback_minutes' => (int) env('MK_DISPATCH_COURIER_FALLBACK_MIN', 5),
    ],

    // Revenue split lives in SellerPayoutService (70/30) — never duplicated here.
];
