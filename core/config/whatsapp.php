<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    | Provider key used when a restaurant hasn't picked one. 'link' = wa.me
    | click-to-chat (no API, no cost) and is the guaranteed built-in fallback.
    | API providers (twilio, meta, ...) register themselves from their plugins.
    */
    'default_provider' => env('WHATSAPP_DEFAULT', 'link'),

    /*
    | When a real provider fails or isn't configured, degrade to a wa.me link
    | so the customer can still reach the restaurant.
    */
    'fallback_to_link' => (bool) env('WHATSAPP_FALLBACK_TO_LINK', true),

    // Per-call HTTP timeout (seconds) and retry attempts on retryable errors.
    'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),
    'retries' => (int) env('WHATSAPP_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Usage / billing (Phase 2+) — see rev/whatsapp/IMPLEMENTATION-SPEC.md §4/§16
    |--------------------------------------------------------------------------
    | 0 = unlimited. Global safety ceiling; per-plan overrides layer on top.
    */
    'monthly_message_limit' => (int) env('WHATSAPP_MONTHLY_LIMIT', 0),

    // 'quota_only' | 'quota_then_wallet' | 'wallet_only'
    'billing_mode' => env('WHATSAPP_BILLING_MODE', 'quota_only'),
    // Fraction (0.2 = 20%). Unset = the billing edition's cost + 20% (PRICING.md §8); the admin setting overrides both.
    'markup_pct'   => env('WHATSAPP_MARKUP_PCT') !== null ? (float) env('WHATSAPP_MARKUP_PCT') : null,
    'markup_flat'  => (float) env('WHATSAPP_MARKUP_FLAT', 0),

    // Per-number throughput cap for broadcast queueing (Phase 7). Meta ~80 MPS.
    'mps' => (int) env('WHATSAPP_MPS', 20),

    // GDPR: purge stored inbound/log bodies older than N days. null = keep.
    'retention_days' => env('WHATSAPP_RETENTION_DAYS', 90),

    // Abandoned-cart recovery window (unpaid orders): remind after N minutes,
    // up to N hours old. OTP code lifetime.
    'cart_recovery_after_minutes' => (int) env('WHATSAPP_CART_AFTER_MIN', 30),
    'cart_recovery_window_hours'  => (int) env('WHATSAPP_CART_WINDOW_HOURS', 24),
    'otp_ttl_minutes' => (int) env('WHATSAPP_OTP_TTL_MIN', 5),

    /*
    |--------------------------------------------------------------------------
    | Wholesale rate map (Phase 3) — [category][country] => cost, 'default' key.
    |--------------------------------------------------------------------------
    | Estimate only; reconciled against provider actuals monthly (§16.4).
    | Categories: marketing | utility | authentication | service (service=free).
    */
    'rates' => [
        'marketing' => ['US' => 0.025, 'GB' => 0.048, 'AE' => 0.0499, 'BR' => 0.0625, 'IN' => 0.0094, 'DE' => 0.124, 'MX' => 0.0305, 'default' => 0.05],
        'utility'   => ['US' => 0.004, 'default' => 0.005],
        'authentication' => ['US' => 0.004, 'default' => 0.005],
        'service'   => ['default' => 0.0],
    ],
];
