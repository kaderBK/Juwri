<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

class PreventRequestForgery extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'ipn/*',
        'webhook/*',
        'webhooks/reseller/*',
        'studio/stripe-webhook',
        'voice/*/incoming',
        'voice/*/status', // P6-09 V-12: provider call-status callback (signature-verified per line)
        'mcp', // #102: JSON-RPC MCP endpoint (bearer-token auth, no session)
        'mcp/dev', // rev/dev-mcp: public read-only dev MCP (JSON-RPC, no session)
        'mcp/storefront', // agent-facing MCP (X-Storefront-Token auth, no session) — was missing, 419'd live
        'mcp/catalog', // P2: public catalog MCP (JSON-RPC, no session)
        'mcp/customer', // P3: customer-account MCP (CustomerTokenAuth, no session)
        'api/v1/*', // token-authed JSON REST (Bearer / X-Storefront-Token, no session) — every POST here 419'd live before this
        'docs/feedback', // DOCS-PLATFORM D6-1: public was-this-helpful signal on cacheable docs pages (no session)
        'help-center/ask', // DOCS-PLATFORM D6-3: docs assistant (dark; 404 while off) — public JSON, no session
        'csp-report', // P0-4: browsers POST CSP violation reports without a token
        'csp-report/payment/*', // PCI DSS 6.4.3: payment-page CSP reports (browser-posted, no token)
        'folio-metrics/*', // FP B8: anonymous flip-book page-view beacon (sendBeacon has no token)
        'dash/client-error', // dashboard client-error beacon (sendBeacon has no token; session rides the cookie)
        'campaigns/unsubscribe/*', // P6-222: RFC 8058 one-click POST from mail providers (signed URL, no session)
        'campaigns/merchant/unsubscribe/*', // RFC 8058 one-click POST from mail providers (signed URL, no session)
        'auth/social/customer/apple/callback', // FEATURES-5 A1: Apple form_post callback (cross-site POST, no session token)
    ];
}
