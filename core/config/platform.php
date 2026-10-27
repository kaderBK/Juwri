<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Untrusted render isolation (Platform v2, P1)
    |--------------------------------------------------------------------------
    |
    | How untrusted theme (Liquid) renders are isolated. See
    | rev/platform-v2/decisions/render-isolation.md.
    |
    |   inprocess  - render in this PHP process under a budget (output + wall-clock).
    |                Safe default for shared/cPanel hosting where proc_open is
    |                often disabled. Security rests on Liquid being data-only +
    |                ResourceLimits (Phase 2).
    |   subprocess - render in a short-lived PHP-CLI worker (open_basedir,
    |                disable_functions, rlimits, scrubbed env). Auto-degrades to
    |                inprocess if proc_open is unavailable.
    |   jailed     - subprocess wrapped in bubblewrap/procjail. Linux/VPS only.
    |
    */

    'render_strategy' => env('RENDER_STRATEGY', 'inprocess'),

    'render_budget' => [
        'max_output_bytes' => (int) env('RENDER_MAX_OUTPUT_BYTES', 1_000_000),
        'max_wall_seconds' => (int) env('RENDER_MAX_WALL_SECONDS', 5),
    ],

    // Command prefix used to wrap the subprocess when strategy = jailed (prod VPS).
    'render_jail_command' => env('RENDER_JAIL_COMMAND', 'bwrap --unshare-all --die-with-parent'),

    /*
    |--------------------------------------------------------------------------
    | Liquid resource limits (P2-1)
    |--------------------------------------------------------------------------
    | Language-layer caps enforced by keepsuit/liquid per render. On shared
    | hosting these ARE the DoS boundary (no OS jail). Tune against real themes.
    */
    'liquid' => [
        'render_length_limit' => (int) env('LIQUID_RENDER_LENGTH_LIMIT', 500_000),
        'render_score_limit' => (int) env('LIQUID_RENDER_SCORE_LIMIT', 500_000),
        'assign_score_limit' => (int) env('LIQUID_ASSIGN_SCORE_LIMIT', 100_000),
        'cumulative_render_score_limit' => (int) env('LIQUID_CUM_RENDER_SCORE_LIMIT', 2_000_000),
        'cumulative_assign_score_limit' => (int) env('LIQUID_CUM_ASSIGN_SCORE_LIMIT', 300_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme Studio section library (B0.1)
    |--------------------------------------------------------------------------
    | Platform-owned, versioned Liquid sections/snippets/locales that Studio
    | themes opt into with `"library": "<version>"` in theme.json.
    */
    'liquid_library' => [
        'path' => env('LIQUID_LIBRARY_PATH'), // null → resource_path('liquid-library')
    ],

    /*
    |--------------------------------------------------------------------------
    | Commerce runtime (P2-8)
    |--------------------------------------------------------------------------
    | Platform-owned cart/checkout JS so sandboxed themes need ZERO custom JS.
    | Served immutably from public/assets/commerce-runtime/<version>/commerce.js;
    | bump the version to ship a new bundle without touching a single theme.
    */
    'commerce' => [
        // Platform default bundle. A Liquid theme may opt into another shipped
        // version (CommerceRuntime::VERSIONS) with theme.json "commerce_runtime"
        // — Theme Studio B0.10: v2 adds the vertical commerce layer.
        'runtime_version' => env('COMMERCE_RUNTIME_VERSION', 'v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order write atomicity (WI-H6)
    |--------------------------------------------------------------------------
    | Order creation writes the order, its lines, its combos and its pricing
    | inside ONE transaction on both paths (OrderApiService::create and
    | StoreController::submitOrder). That is the correct behaviour and the default.
    | The env switch exists only as an incident lever: if row locking or a
    | deadlock storm takes checkout down in prod, the owner can restore the old
    | unwrapped writes in seconds without a deploy. Turning it OFF re-opens the
    | half-written-order defect — it is not a setting, it is a fire alarm.
    | Config is cached in prod: run `php artisan config:clear` after changing it.
    */
    'orders' => [
        'atomic_create' => (bool) env('ORDER_ATOMIC_CREATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace quality gate (P4-3)
    |--------------------------------------------------------------------------
    | Shopify-grade Lighthouse bars enforced on theme submissions: average
    | performance and minimum accessibility across home+menu, desktop+mobile.
    */
    'lighthouse' => [
        'min_performance' => (int) env('LH_MIN_PERFORMANCE', 60),
        'min_accessibility' => (int) env('LH_MIN_ACCESSIBILITY', 90),
        'timeout' => (int) env('LH_TIMEOUT', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace revenue share (P4-6, Option A)
    |--------------------------------------------------------------------------
    | The seller's fraction of every theme sale / app charge, credited to
    | their wallet. Default 70/30 seller/platform (Shopify-style).
    */
    'marketplace' => [
        'seller_share' => (float) env('MARKETPLACE_SELLER_SHARE', 0.70),
        // P9-5b: which cash-out rail is active. 'manual' = superadmin records an
        // external reference; 'stripe_connect' = a Stripe Transfer to the seller's
        // connected account. Both debit the wallet at request time.
        'payout_rail' => env('MARKETPLACE_PAYOUT_RAIL', 'manual'),
        'payout_currency' => env('MARKETPLACE_PAYOUT_CURRENCY', 'usd'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reseller commission (Coupons & Wallets P2-4) — NO LONGER PAID
    |--------------------------------------------------------------------------
    | Pricing rework (owner 2026-10-05, Reseller Agreement §6.5): resellers earn
    | no commission, whatever RESELLER_COMMISSION_SHARE says; their margin is the
    | tier's wholesale discount. The key stays so an existing .env still parses.
    */
    'reseller' => [
        'commission_share' => (float) env('RESELLER_COMMISSION_SHARE', 0),
        // Reseller v2 P2 (2.9) reseller-refers-reseller payout — REMOVED (owner
        // 2026-10-05, Reseller Agreement §6.5; referrals use the Affiliate
        // Programme). Nothing reads these two keys; kept so an old .env parses.
        'referral_share' => (float) env('RESELLER_REFERRAL_SHARE', 0.20),
        'referral_months' => (int) env('RESELLER_REFERRAL_MONTHS', 12),
        // P5 (5.1): BYOK gateway drivers offered to resellers (admin-toggle).
        // MoR Wave R: paddle + lemon_squeezy (reseller's own MoR accounts).
        'gateways' => ['stripe', 'paypal', 'tlync', 'paddle', 'lemon_squeezy'],
        // P7 Rail B (owner decision #3): Stripe Connect, default OFF (designated cut).
        'connect_enabled' => (bool) env('RESELLER_CONNECT_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics alert thresholds (P5-3)
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'max_render_failure_rate' => (float) env('ALERT_MAX_RENDER_FAIL_RATE', 0.05),
        'max_app_auth_fails' => (int) env('ALERT_MAX_APP_AUTH_FAILS', 100),
        'max_app_payment_fails' => (int) env('ALERT_MAX_APP_PAYMENT_FAILS', 50),
        // H6: alert when order creation starts rolling back.
        'max_order_create_fails' => (int) env('ALERT_MAX_ORDER_CREATE_FAILS', 10),
        // P5-5: abuse thresholds
        'max_theme_render_fails' => (int) env('ALERT_MAX_THEME_RENDER_FAILS', 25),
        'auto_delist_breaching_themes' => (bool) env('ALERT_AUTO_DELIST_THEMES', false),
        'max_csp_violations_per_host' => (int) env('ALERT_MAX_CSP_VIOLATIONS', 50),
        // O7: alert when the newest platform DB backup is older than this.
        'max_backup_age_hours' => (int) env('ALERT_MAX_BACKUP_AGE_HOURS', 26),
        // C2: alert when the weekly restore drill has not passed in this long.
        'max_restore_check_age_days' => (int) env('ALERT_MAX_RESTORE_CHECK_AGE_DAYS', 8),
        // O8: queue backlog thresholds (database queue driver).
        'max_queue_depth' => (int) env('ALERT_MAX_QUEUE_DEPTH', 500),
        // O6: alert when failed_jobs exceeds this.
        'max_failed_jobs' => (int) env('ALERT_MAX_FAILED_JOBS', 25),
        'max_queue_age_seconds' => (int) env('ALERT_MAX_QUEUE_AGE_SECONDS', 900),
    ],

    /*
    | P1-23: personal-data breach notices to customers (security:breach-notice; runbook
    | deploy/SECURITY-INCIDENT-RUNBOOK.md). Deadlines are counted from the moment the breach is
    | confirmed: 48 h (Terms 9.2, DPA 6.1, security page 8), 24 h for customers established in
    | Kuwait (DPA Schedule 4 D2). `bcc` receives a copy of every notice (juwri Cloud:
    | security@juwri.com, DPA 6.2); empty on a self-hosted install unless the operator sets it.
    */
    'breach_notice' => [
        'hours' => (int) env('BREACH_NOTICE_HOURS', 48),
        'hours_kuwait' => (int) env('BREACH_NOTICE_HOURS_KUWAIT', 24),
        'bcc' => env('BREACH_NOTICE_BCC', ''),
    ],
];
