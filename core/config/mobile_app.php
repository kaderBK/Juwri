<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 |--------------------------------------------------------------------------
 | Owner mobile app (Capacitor shell)
 |--------------------------------------------------------------------------
 | Everything the native shell reads from the server. The app itself renders
 | the live dashboard in a WebView, so a server deploy IS an app release —
 | `min_version` is the lever that forces an update when a deploy needs a newer
 | shell. Spec: rev/mobile-app/IMPLEMENTATION-SPEC.md.
 */

return [

    // Master switch for the app API (login, session hand-off, devices). Push has
    // its own feature gate (`mobile_app_push`, see MobilePushService).
    'enabled' => (bool) env('MOBILE_APP_ENABLED', true),

    // Bundle id / application id. Used by the deep-link association files.
    'app_id' => env('MOBILE_APP_ID', 'com.juwri.owner'),

    // Oldest shell version allowed to run. Below this the app must show its
    // "update required" screen. Semver strings, compared numerically.
    'min_version' => [
        'ios' => env('MOBILE_APP_MIN_VERSION_IOS', '1.0.0'),
        'android' => env('MOBILE_APP_MIN_VERSION_ANDROID', '1.0.0'),
    ],

    // Newest published shell version (drives the soft "update available" hint).
    'latest_version' => [
        'ios' => env('MOBILE_APP_LATEST_VERSION_IOS', '1.0.0'),
        'android' => env('MOBILE_APP_LATEST_VERSION_ANDROID', '1.0.0'),
    ],

    'store_urls' => [
        'ios' => env('MOBILE_APP_IOS_STORE_URL', ''),
        'android' => env('MOBILE_APP_ANDROID_STORE_URL', ''),
    ],

    // App tokens slide: every session hand-off pushes expiry out again, so an
    // owner who opens the app at least once per window never has to log in.
    'token_ttl_days' => (int) env('MOBILE_APP_TOKEN_TTL_DAYS', 180),

    // One-time session hand-off URL lifetime (seconds). Short on purpose: the
    // app opens it immediately; a leaked URL must die fast.
    'handoff_ttl_seconds' => 60,

    // The shell appends "<token>/<version> (<platform>)" to the WebView user
    // agent so server code can tell it is running inside the app.
    'user_agent_token' => 'JuwriApp',

    // ── Deep links (universal links / app links) ────────────────────────────
    // Both association files 404 until these are set, so an unconfigured
    // install never advertises an app it does not have.
    'apple_team_id' => env('MOBILE_APP_APPLE_TEAM_ID', ''),

    // Comma-separated SHA-256 signing-cert fingerprints (AA:BB:…) for Android.
    'android_sha256_fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MOBILE_APP_ANDROID_SHA256', ''))
    ))),

    // Paths the app claims. Main domain only in v1 (per-store custom domains
    // are not deep-linked). Anything else opens in the browser.
    'deep_link_paths' => [
        '/dashboard',
        '/dashboard/*',
        '/orders',
        '/orders/*',
        '/restaurants/*',
        '/reservations',
        '/reservations/*',
        '/kds',
        '/kds/*',
        '/customers',
        '/customers/*',
        '/reports',
        '/reports/*',
        '/analytics',
        '/analytics/*',
    ],
];
