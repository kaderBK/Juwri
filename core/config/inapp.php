<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P5-17: in-app billing of the owner app (owner decision 2026-10-08: plans and subscriptions are sold in the app through
 * Apple and Google). Both stores are OFF until real keys exist. Products map a store product id to a platform plan and its
 * billing interval; every grant, renewal, grace, expiry and refund goes through the same billing state machine as the
 * web checkout (Payments Fulfilment → Billing).
 */
return [
    'apple' => [
        'enabled' => (bool) env('INAPP_APPLE_ENABLED', false),
        'bundle_id' => env('INAPP_APPLE_BUNDLE_ID', ''),
        // StoreKit environments accepted: Production only in production; add Sandbox for TestFlight / review builds
        'environments' => array_values(array_filter(explode(',', (string) (env('INAPP_APPLE_ENVIRONMENTS') ?: 'Production')))),
        // The trust anchor, bundled (no download): SHA-256 of Apple Root CA - G3 (DER). The x5c chain of every signed
        // transaction / notification must end in this exact certificate. Check it against apple.com/certificateauthority.
        'root_sha256' => strtolower(str_replace(':', '', (string) (env('INAPP_APPLE_ROOT_SHA256')
            ?: '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179'))),
        'products' => [
            // 'com.juwri.owner.pro.monthly' => ['plan_id' => 2, 'interval' => 'MONTHLY', 'amount' => '29.00', 'currency' => 'USD'],
        ],
    ],
    'google' => [
        'enabled' => (bool) env('INAPP_GOOGLE_ENABLED', false),
        'package_name' => env('INAPP_GOOGLE_PACKAGE', ''),
        // service-account key JSON (from config / secret store only, never a file the app downloads)
        'service_account' => env('INAPP_GOOGLE_SERVICE_ACCOUNT_JSON', ''),
        // Pub/Sub push (RTDN): the OIDC token's audience and the push service account it must come from
        'push_audience' => env('INAPP_GOOGLE_PUSH_AUDIENCE', ''),
        'push_service_account' => env('INAPP_GOOGLE_PUSH_SERVICE_ACCOUNT', ''),
        'products' => [
            // 'juwri_pro_monthly' => ['plan_id' => 2, 'interval' => 'MONTHLY', 'amount' => '29.00', 'currency' => 'USD'],
        ],
    ],
];
