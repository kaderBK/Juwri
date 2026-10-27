<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Performance tuning
    |--------------------------------------------------------------------------
    |
    | menu_cache_ttl  Seconds to cache the assembled storefront menu payload
    |                 (P5). 0 disables the cache entirely (kill switch). The
    |                 payload is busted on any menu write regardless of TTL.
    | menu_etag       Send an ETag + honour If-None-Match (304) on the anonymous
    |                 storefront menu response (P5).
    | post_view_retention  Days to keep raw store_page_views rows once rolled up (P8).
    |                 0 disables pruning (nothing is deleted).
    |
    */

    'menu_cache_ttl' => (int) env('MENU_CACHE_TTL', 300),
    'menu_etag' => (bool) env('MENU_ETAG', true),
    'post_view_retention' => (int) env('POST_VIEW_RETENTION_DAYS', 0),

    /*
    | storefront_fullpage_cache  Master switch for the anonymous storefront
    |                 full-page cache (CacheStorefrontResponse). OFF by default —
    |                 turned on for a canary set of stores first (see below).
    | storefront_fullpage_cache_canary  Comma-separated store slugs. When non-empty,
    |                 ONLY these stores are full-page cached (canary). Empty = all
    |                 stores (once the master switch is on).
    | storefront_fullpage_cache_ttl  Backstop TTL in seconds (invalidation is
    |                 primarily token-driven via StorefrontMenuCache::version).
    */
    'storefront_fullpage_cache' => (bool) env('STOREFRONT_FULLPAGE_CACHE', false),
    'storefront_fullpage_cache_canary' => (string) env('STOREFRONT_FULLPAGE_CACHE_CANARY', ''),
    'storefront_fullpage_cache_ttl' => (int) env('STOREFRONT_FULLPAGE_CACHE_TTL', 3600),
    /* The account owner's per-store trial switch (Backup page, store setting `storefront_fullpage_cache_trial`, off by default): the
       store's anonymous home page is cached for this many seconds even while the master switch above is off. */
    'storefront_fullpage_cache_trial_ttl' => (int) env('STOREFRONT_FULLPAGE_CACHE_TRIAL_TTL', 300),

    /*
    | storefront_home_page_threshold / storefront_home_page_items  (P4-16 load test) A food storefront (table, brew,
    |                 basket) with more than `threshold` items draws only the first `items` cards in the page (the first
    |                 categories in order) and every other category shows its heading with a "Show more" control that loads
    |                 the cards in place (`?fragment=cat`). 2,000 items were a 5 MB page. At or below the threshold nothing changes.
    | storefront_catalog_auto_page  The headless catalogue API (`GET /catalog`) answers a food store with more items than this
    |                 in pages of `per_page` (default 48) with `next_cursor`, like a goods store; a smaller one stays one page.
    */
    /*
    | storefront_catalog_cache_ttl  Seconds the built catalogue cards of a store are kept (ViewModelBuilder::cardsCached), under the
    |                 store's menu-cache token. 0 = off. A sold-out flip or stock change that bypasses the model events shows within this time;
    |                 the order path always re-reads the items and refuses a sold-out one. The test suite sets 0 (phpunit.xml).
    */
    'storefront_catalog_cache_ttl' => (int) env('STOREFRONT_CATALOG_CACHE_TTL', 60),
    'storefront_home_page_threshold' => (int) env('STOREFRONT_HOME_PAGE_THRESHOLD', 200),
    'storefront_home_page_items' => (int) env('STOREFRONT_HOME_PAGE_ITEMS', 60),
    'storefront_catalog_auto_page' => (int) env('STOREFRONT_CATALOG_AUTO_PAGE', 300),

];
