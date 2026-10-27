<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P5-21: KSA SoftPOS (card on the cashier's phone) through each store's OWN acquirer account (Geidea, EdfaPay). Money
 * settles to the store; juwri only records. Every provider is OFF until its partner onboarding is done. API paths and the
 * callback signature formats follow the providers' published gateway conventions and MUST be confirmed against the
 * SoftPOS partner documentation at onboarding (they are config, not code). Offline card stays off until OP-0
 * (pos.offline.card_enabled; caps SAR 250 per payment / SAR 2,500 per till in config/pos.php).
 */
return [
    'geidea' => [
        'enabled' => (bool) env('SOFTPOS_GEIDEA_ENABLED', false),
        'base_url' => env('SOFTPOS_GEIDEA_BASE_URL', 'https://api.ksamerchant.geidea.net'),
        'paths' => ['create' => '/softpos/v1/payments', 'show' => '/softpos/v1/payments/{ref}', 'refund' => '/softpos/v1/payments/{ref}/refund', 'void' => '/softpos/v1/payments/{ref}/void'],
        'offline_supported' => (bool) env('SOFTPOS_GEIDEA_OFFLINE', false), // deferred authorisation (OP-0)
    ],
    'edfapay' => [
        'enabled' => (bool) env('SOFTPOS_EDFAPAY_ENABLED', false),
        'base_url' => env('SOFTPOS_EDFAPAY_BASE_URL', 'https://api.edfapay.com'),
        'paths' => ['create' => '/softpos/v1/transactions', 'show' => '/softpos/v1/transactions/{ref}', 'refund' => '/softpos/v1/transactions/{ref}/refund', 'void' => '/softpos/v1/transactions/{ref}/void'],
        'offline_supported' => (bool) env('SOFTPOS_EDFAPAY_OFFLINE', false),
    ],
];
