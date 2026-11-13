<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Pharmacy selling per country (P1-23, Acceptable Use Policy 3.1)
|--------------------------------------------------------------------------
|
| Owner decision 2026-10-04: pharmacy selling is enabled per country only, and off until the owner enables it
| for each country. A pharmacy store is shown and sells online only when its market (the store's
| pharmacy country, else its fiscal country, else the owner's country) is listed here AND its
| licence documents were reviewed and approved and have not expired.
|
| enabled_countries   ISO 3166 alpha-2 codes, comma separated. Empty (the default) = no country.
| quantity_cap        default maximum units of one medicine per order; a store may only lower it.
| block_medicine_promotions
|                     the per-market promotion block (DECISIONS 2026-10-04): when on, no promotion touches a
|                     medicine line (items.is_medicine) of a pharmacy store — coupons, scheduled / sale / happy-hour
|                     prices, combos or bundles holding a medicine, loyalty points earned or redeemed on medicines.
|                     Non-medicine lines are unaffected. Default for markets not listed: off.
| default_markets     the built-in per-country rules: Saudi Arabia blocks medicine promotions (SFDA rules).
| markets             per-country overrides as JSON, merged over default_markets, e.g.
|                     {"GB":{"quantity_cap":2},"AE":{"block_medicine_promotions":true}} — set by the owner.
|                     The older key "promotions" (true = allowed) is still read when the new one is absent.
*/

return [
    'enabled_countries' => array_values(array_filter(array_map(
        fn ($c) => strtoupper(trim($c)),
        explode(',', (string) env('PHARMACY_ENABLED_COUNTRIES', ''))
    ), fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1)),

    'quantity_cap' => max(1, (int) env('PHARMACY_QUANTITY_CAP', 2)),

    'block_medicine_promotions' => (bool) env('PHARMACY_BLOCK_MEDICINE_PROMOTIONS', false),

    'default_markets' => [
        'SA' => ['block_medicine_promotions' => true],
    ],

    'markets' => (array) (json_decode((string) env('PHARMACY_MARKETS', '{}'), true) ?: []),

    // Licence documents: private disk, max size (KB) and types.
    'licence_disk' => env('PHARMACY_LICENCE_DISK', 'local'),
    'licence_max_kb' => (int) env('PHARMACY_LICENCE_MAX_KB', 8192),
];
