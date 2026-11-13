<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | API rate limits (per minute)
    |--------------------------------------------------------------------------
    |
    | Per-token ceilings for the v1 API groups. Keyed on a hash of the bearer /
    | query token so each token gets its own budget instead of sharing one
    | per-IP bucket (A1). Owners can tune these via env without a code change.
    |
    */

    // The `api` middleware group's own per-IP (per user when signed in) ceiling, applied to every API route on top of the
    // per-token limits below. A server-side theme or a proxy puts many shoppers behind ONE address: raise it there.
    'ip_rate' => (int) env('API_IP_RATE', 60),
    'owner_rate' => (int) env('API_OWNER_RATE', 120),
    'reseller_rate' => (int) env('API_RESELLER_RATE', 60),
    'storefront_rate' => (int) env('API_STOREFRONT_RATE', 120),
    // P3-06b (kit/api README §2.9): writes of the headless storefront API (quote, orders, customer OTP) have their own
    // budget per token AND, for browser tokens, per token + IP; plus a ceiling per account (tenancy.rate_limits).
    'storefront_write_rate' => (int) env('API_STOREFRONT_WRITE_RATE', 30),

    /*
    |--------------------------------------------------------------------------
    | Headless storefront API (P3-06b)
    |--------------------------------------------------------------------------
    | legacy_sunset: the HTTP-date sent as `Sunset` on the deprecated aliases (GET /storefront/menu, /storefront/
    | restaurant, the public /menu/{id} and /track/{token}, the legacy order body) — launch + 12 months (API-4). The
    | default is the kit contract's (launch 2026-10-03); set it to the real launch + 12 months.
    | hosted_window_minutes: the hosted payment window (API-10, owner decision: 15 minutes).
    | quote_ttl_seconds: how long a quote_hash is offered for (QuoteResponse.expires_at).
    */
    'storefront' => [
        'legacy_sunset' => (string) env('API_STOREFRONT_LEGACY_SUNSET', 'Sun, 03 Oct 2027 00:00:00 GMT'),
        'hosted_window_minutes' => 15,
        'quote_ttl_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP browser origins
    |--------------------------------------------------------------------------
    |
    | MCP clients normally omit Origin. Browser clients must be same-origin or
    | match one of these exact comma-separated origins (scheme + host + port).
    |
    */

    'mcp_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))
    ))),


    /*
    |--------------------------------------------------------------------------
    | OpenAPI docs (dedoc/scramble, P1-17)
    |--------------------------------------------------------------------------
    | GET /docs/api (UI) and /docs/api.json (OpenAPI 3.1) document api/v1 only. They are served in the "local"
    | environment and refused (403) everywhere else unless API_DOCS_PUBLIC=true. Off by default; making them public is the
    | owner's decision (it publishes every endpoint's shape).
    */
    'docs_public' => (bool) env('API_DOCS_PUBLIC', false),
];
