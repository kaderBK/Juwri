<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Origins by trust level (P1-20, J28)
|--------------------------------------------------------------------------
|
| `single` (default): today's behaviour — one host (APP_URL) serves marketing, the owner dashboard, the
| superadmin panel and the path-form storefronts (`/{slug}`); verified custom domains and platform
| subdomains serve their store. Existing installs, dev and the test suite run like this.
|
| `split`: each trust level on its own host —
|   MARKETING_URL   marketing pages only, no dashboard session;
|   APP_URL         owner/staff dashboard + login, own `__Host-` session cookie, refuses superadmin logins;
|   APP_ADMIN_URL   superadmin only, own login + cookie, shorter idle timeout, optional IP allowlist;
|   STOREFRONT_URL  every store address (`/{slug}`, `{label}.<storefront host>`, verified custom domains),
|                   own customer cookie per host, never dashboard/admin routes.
| Flip with HOST_MODE=split once the four hosts resolve (DNS + TLS). All host names are configuration.
|
*/

return [

    'mode' => env('HOST_MODE', 'single'),

    'app_url' => env('APP_URL', 'http://localhost'),
    'admin_url' => env('APP_ADMIN_URL'),
    'storefront_url' => env('STOREFRONT_URL'),
    'marketing_url' => env('MARKETING_URL'),

    /*
     * Labels a store can never take as a platform subdomain (J28 / SPEC P1-20 §7).
     */
    'reserved_subdomains' => [
        'www', 'api', 'app', 'admin', 'mail', 'static', 'cdn', 'assets', 'status',
        'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2', 'mx', 'webmail', 'autodiscover', 'autoconfig',
        'dashboard', 'login', 'auth', 'id', 'sso', 'help', 'support', 'docs', 'blog', 'dev', 'staging', 'test',
    ],

    /*
     * Non-primary store addresses answer GET/HEAD with a 301 to the primary one (path + query kept).
     */
    'redirect_to_primary' => (bool) env('STORE_PRIMARY_REDIRECT', true),

    /*
     * Custom-domain verification proof. `txt` (default): the TXT record carrying the store's own token is
     * required — a CNAME alone only proves the DNS points at the platform, not which store owns it (a
     * dangling CNAME would let any store claim the host). `cname_or_txt`: the inherited rule.
     */
    /** Hours an unverified (pending) custom-domain request keeps its host reserved against other stores; after that another store may claim it. */
    'pending_grace_hours' => (int) env('DOMAIN_PENDING_GRACE_HOURS', 72),

    // P6-241: a verified custom domain that stops pointing at the platform (no TXT token, no CNAME / A record to us) for
    // this many days in a row is released by the daily storefront:domains-recheck, so the host's new owner can claim it.
    'release_after_days' => (int) env('DOMAIN_RELEASE_AFTER_DAYS', 14),

    'domain_verification' => env('DOMAIN_VERIFICATION', 'txt'),

    // BL-036 R2: days an unverified reseller custom-domain request holds its host against other resellers and stores;
    // after that the request is stale (it no longer blocks anyone and must be made again before it can be verified).
    'reseller_request_days' => (int) env('RESELLER_DOMAIN_REQUEST_DAYS', 14),

    // BL-036 R2: seconds a domain claim (request / verify / approve) waits for another claim on the same host before it
    // answers "busy, please retry".
    'claim_lock_wait_seconds' => 10,

    /*
     * BL-036 R2: a migrated install's imported store domains may already carry the source platform's DNS proof. For
     * those rows only, the TXT may also sit on one of these challenge labels (`<label>.<host>`) and carry one of these
     * value prefixes (`<prefix><token>`). Comma lists, empty by default; set on the migrated install only.
     */
    'legacy_verify_labels' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOMAIN_LEGACY_VERIFY_LABELS', ''))))),
    'legacy_txt_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOMAIN_LEGACY_TXT_PREFIXES', ''))))),

    /*
     * Split mode: per-origin session cookie names (brand-neutral: a reseller's host shows them too). The `__Host-` prefix (Secure, Path=/, no Domain) is used
     * only when session cookies are Secure (SESSION_SECURE_COOKIE / production) — browsers reject it on http.
     */
    'cookies' => [
        'app' => env('APP_SESSION_COOKIE', 'site_app'),
        'admin' => env('ADMIN_SESSION_COOKIE', 'site_admin'),
        'storefront' => env('STOREFRONT_SESSION_COOKIE', 'site_store'),
        'marketing' => env('MARKETING_SESSION_COOKIE', 'site_marketing'),
    ],

    /*
     * Shared secret for the Caddy on-demand TLS `ask` endpoint (GET /api/tls/ask?domain=…&token=…); empty = none.
     */
    'tls_ask_token' => env('TLS_ASK_TOKEN', ''),

    'admin' => [
        // Minutes; split mode only (the admin host's session lifetime and AdminSessionGuard idle timeout).
        'session_lifetime' => (int) env('ADMIN_SESSION_LIFETIME', 30),
        // Comma list of IPs / CIDRs allowed on the admin host; empty = no allowlist.
        'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),
    ],
];
