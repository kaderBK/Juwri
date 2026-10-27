<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Abuse Guard mode resolution (S6)
|--------------------------------------------------------------------------
|
| Every guard runs in one of: off | warn | enforce. The effective mode is
| resolved by AbuseGuard::mode() with this precedence:
|
|   1. admin DB override  — config('settings.abuse_<guard>_mode'), if explicitly set
|   2. per-guard env       — config('abuse.modes.<guard>')
|   3. global default      — config('abuse.default_mode')  (env ABUSE_MODE)
|
| P6-55: the code default is 'enforce' when APP_ENV=production and 'warn'
| everywhere else, so every guard blocks in production unless ABUSE_MODE or
| ABUSE_MODE_<GUARD> says otherwise (ops:preflight flags that).
|
*/

return [

    // Public QR table / room key lookups (storefront ?table=, hotel guest info): misses allowed per store per minute,
    // per visitor session and per client network (IPv4 / IPv6 /64), before every key lookup for that store answers
    // "unknown" without a query (Juwri\Core\Services\TableKeyGuard).
    'table_key_misses_per_minute' => (int) env('ABUSE_TABLE_KEY_MISSES_PER_MINUTE', 20),
    'table_key_misses_per_minute_network' => (int) env('ABUSE_TABLE_KEY_MISSES_PER_MINUTE_NETWORK', 100),

    // QR scans: at most one counted scan per client network per store per this many seconds (ScanDedupe).
    'scan_dedupe_seconds' => (int) env('ABUSE_SCAN_DEDUPE_SECONDS', 30),

    'default_mode' => env('ABUSE_MODE', env('APP_ENV') === 'production' ? 'enforce' : 'warn'),

    // P6-74: per-store, per-UTC-day ceilings on public endpoints that spend our money (0 = unlimited). Override with the
    // admin settings abuse_store_cap_<bucket>_daily / abuse_store_cap_<bucket>_daily_<plan slug>.
    'store_daily_caps' => [
        'ai' => (int) env('ABUSE_STORE_CAP_AI', 500),
        'sms' => (int) env('ABUSE_STORE_CAP_SMS', 200),
        // P6-261: checkout OTP sends with no store (platform SMS account) — their own small bucket
        'sms_platform' => (int) env('ABUSE_STORE_CAP_SMS_PLATFORM', 50),
        'help_ai' => (int) env('ABUSE_STORE_CAP_HELP_AI', 2000),
    ],

    // P6-74/74b: per-caller per-STORE per-UTC-day sub-ceilings on the same buckets (0 = off); override:
    // abuse_store_cap_<bucket>_ip_daily. The counter key includes the store id, so a shared IP (carrier NAT, cafe or
    // campus Wi-Fi) is limited separately at each store. Logged-in customers skip the IP count and are counted per
    // customer id instead (same ceiling). SMS is 60 (was 30) because OTP sends are legitimate and bursty behind one NAT.
    'store_daily_ip_caps' => [
        'ai' => (int) env('ABUSE_STORE_IP_CAP_AI', 100),
        'sms' => (int) env('ABUSE_STORE_IP_CAP_SMS', 60),
        'sms_platform' => (int) env('ABUSE_STORE_IP_CAP_SMS_PLATFORM', 10),
        'help_ai' => (int) env('ABUSE_STORE_IP_CAP_HELP_AI', 50),
    ],

    // P6-261: platform-wide per-UTC-day ceiling per bucket across ALL stores (0 = off); override
    // abuse_store_cap_<bucket>_global_daily. A cost backstop far above any one store's cap.
    'store_daily_global_caps' => [
        'sms' => (int) env('ABUSE_GLOBAL_CAP_SMS', 20000),
    ],

    // P6-261: checkout OTP — one phone number gets at most this many checkout codes a day across all stores (anti SMS-bombing; 0 = off).
    'checkout_otp' => [
        'phone_daily' => (int) env('ABUSE_CHECKOUT_OTP_PHONE_DAILY', 15),
    ],

    // Per-guard overrides. A null (unset) entry falls through to default_mode.
    'modes' => [
        'webhook_in' => env('ABUSE_MODE_WEBHOOK_IN'),
        'upload' => env('ABUSE_MODE_UPLOAD'),
        'public_write' => env('ABUSE_MODE_PUBLIC_WRITE'),
        'signup' => env('ABUSE_MODE_SIGNUP'),
        'mcp' => env('ABUSE_MODE_MCP'),
        'ai' => env('ABUSE_MODE_AI'),
        'store_cap' => env('ABUSE_MODE_STORE_CAP'),
    ],

];
