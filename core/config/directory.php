<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * DIRECTORY L3 (T3-1/T3-2): Overture Maps ingest configuration.
 *
 * Overture Maps ONLY (CDLA-Permissive-2.0, mixable + monetizable). NEVER
 * OpenStreetMap/Overpass (ODbL share-alike), NEVER Google/Yelp/TripAdvisor
 * (D4) — those are licence/ToS violations, not preferences.
 *
 * The ingest shells out to the `duckdb` CLI (D12) so it never becomes a
 * runtime dependency for serving traffic — if the binary is absent the
 * artisan commands print install instructions and exit 1.
 */
return [

    'overture' => [
        // Overture release tag, e.g. "2026-06-25.0" — see
        // https://docs.overturemaps.org/release/latest/ for the current one.
        'release' => env('DIRECTORY_OVERTURE_RELEASE', 'latest'),

        // Public, no-auth-required S3 bucket DuckDB reads via httpfs.
        's3_base' => env('DIRECTORY_OVERTURE_S3_BASE', 's3://overturemaps-us-west-2/release'),

        // Overturemaps releases live in AWS us-west-2; duckdb's httpfs needs
        // region set even for anonymous/no-sign-request reads.
        's3_region' => env('DIRECTORY_OVERTURE_S3_REGION', 'us-west-2'),

        'duckdb_binary' => env('DIRECTORY_DUCKDB_BIN', 'duckdb'),

        // City bbox half-width in km used by both dir:coverage and dir:import
        // (a square around the city centre — good enough at v1 scale; a real
        // per-city polygon is a future seam, see Marketplace Zone geometry).
        'bbox_radius_km' => (float) env('DIRECTORY_BBOX_RADIUS_KM', 12),

        // Conflation radius (T3-2/D-conflation): how close an Overture place
        // must be to an existing Post's mk_lat/mk_lng to count as the same
        // real business rather than a new unclaimed listing.
        'conflation_radius_m' => (float) env('DIRECTORY_CONFLATION_RADIUS_M', 100),

        // Overture `categories.primary` values worth importing at all — only
        // food & drink places, never the full places dataset.
        'category_prefixes' => [
            'restaurant', 'cafe', 'coffee_shop', 'bar', 'pub', 'bakery',
            'fast_food', 'food_truck', 'dessert_shop', 'ice_cream_shop',
            'juice_bar', 'pizza_restaurant', 'burger_restaurant',
            'seafood_restaurant', 'sushi_restaurant', 'buffet',
        ],

        // Overture `categories.primary` -> our `cuisines.slug`. Unmapped
        // categories still import (category_raw is kept verbatim) with
        // cuisine_id left null for later curation — see T1-1's backfill
        // command, which scores suggestions for exactly these leftovers.
        'category_cuisine_map' => [
            'pizza_restaurant' => 'pizza',
            'burger_restaurant' => 'burgers',
            'seafood_restaurant' => 'seafood',
            'sushi_restaurant' => 'sushi',
            'japanese_restaurant' => 'sushi',
            'coffee_shop' => 'coffee',
            'cafe' => 'coffee',
            'bakery' => 'desserts',
            'dessert_shop' => 'desserts',
            'ice_cream_shop' => 'desserts',
            'indian_restaurant' => 'indian',
            'pakistani_restaurant' => 'pakistani',
            'middle_eastern_restaurant' => 'levantine',
            'lebanese_restaurant' => 'levantine',
            'shawarma_restaurant' => 'shawarma',
            'mediterranean_restaurant' => 'levantine',
        ],
    ],

];
