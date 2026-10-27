<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Delivery-aggregator commission presets for the /tools/commission-calculator
 * page (SEO run W2.1). Figures are the published take rates from KEYWORDS.md
 * §Commissions (2026-08-30 SERP research) — cited in copy as "up to". The
 * `rate` used for the estimate is the top of each published range; `rate_label`
 * is the verbatim range shown to the reader so the number is never presented as
 * a precise fee.
 *
 * `name` is an English string translated in the view via ___(); aggregator
 * names are proper nouns (never translated). Currency `symbol`/`pos` drive
 * display only — the arithmetic is currency-agnostic (orders × ticket × rate).
 *
 * This file is required by both the controller (to expose the data to JS) and
 * the test (to assert the table server-side), so it stays pure data — no ___()
 * or Blade in here.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'tr' => [
        'name' => 'Türkiye',
        'aggregator' => 'Yemeksepeti',
        'rate' => 0.40,
        'rate_label' => '15–40%',
        'currency' => 'TRY',
        'symbol' => '₺',
        'pos' => 'suffix',
        'ticket' => 250,
        'orders' => 700,
    ],
    'br' => [
        'name' => 'Brazil',
        'aggregator' => 'iFood',
        'rate' => 0.27,
        'rate_label' => '~23–27%',
        'currency' => 'BRL',
        'symbol' => 'R$',
        'pos' => 'prefix',
        'ticket' => 55,
        'orders' => 600,
    ],
    'de' => [
        'name' => 'Germany',
        'aggregator' => 'Lieferando',
        'rate' => 0.30,
        'rate_label' => '13% self-delivery / 25–30% with drivers',
        'currency' => 'EUR',
        'symbol' => '€',
        'pos' => 'suffix',
        'ticket' => 25,
        'orders' => 600,
    ],
    'fr' => [
        'name' => 'France',
        'aggregator' => 'Uber Eats / Deliveroo',
        'rate' => 0.30,
        'rate_label' => '15–30% (real cost 33–36%)',
        'currency' => 'EUR',
        'symbol' => '€',
        'pos' => 'suffix',
        'ticket' => 25,
        'orders' => 600,
    ],
    'sa' => [
        'name' => 'Saudi Arabia',
        'aggregator' => 'Talabat',
        'rate' => 0.30,
        'rate_label' => 'up to ~30%',
        'currency' => 'SAR',
        'symbol' => 'SR',
        'pos' => 'prefix',
        'ticket' => 60,
        'orders' => 600,
    ],
    'ae' => [
        'name' => 'United Arab Emirates',
        'aggregator' => 'Talabat',
        'rate' => 0.30,
        'rate_label' => 'up to ~30%',
        'currency' => 'AED',
        'symbol' => 'AED',
        'pos' => 'prefix',
        'ticket' => 55,
        'orders' => 600,
    ],
    'pk' => [
        'name' => 'Pakistan',
        'aggregator' => 'foodpanda',
        'rate' => 0.30,
        'rate_label' => '15–30%',
        'currency' => 'PKR',
        'symbol' => 'Rs',
        'pos' => 'prefix',
        'ticket' => 900,
        'orders' => 600,
    ],
    'in' => [
        'name' => 'India',
        'aggregator' => 'Zomato / Swiggy',
        'rate' => 0.30,
        'rate_label' => '~18–30%',
        'currency' => 'INR',
        'symbol' => '₹',
        'pos' => 'prefix',
        'ticket' => 400,
        'orders' => 600,
    ],
];
