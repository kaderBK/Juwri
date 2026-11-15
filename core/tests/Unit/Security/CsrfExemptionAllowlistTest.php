<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\Security;

use App\Http\Middleware\PreventRequestForgery;
use PHPUnit\Framework\TestCase;

/**
 * Lane C CSRF audit (2026-10-13): every exemption was reviewed. Each is either
 * signature/token-authed (webhooks, provider callbacks, MCP, api/v1), a browser
 * beacon that cannot send a token (CSP reports, sendBeacon), or an anonymous,
 * session-free write on shared-cache pages (docs/feedback, help-center/ask).
 * Adding an exemption must be a deliberate, reviewed change to this list.
 */
class CsrfExemptionAllowlistTest extends TestCase
{
    public function test_csrf_exemptions_match_reviewed_allowlist(): void
    {
        $except = (fn () => $this->except)->call(new class extends PreventRequestForgery
        {
            public function __construct() {}
        });

        $this->assertSame([
            'ipn/*', 'webhook/*', 'webhooks/reseller/*', 'studio/stripe-webhook',
            'voice/*/incoming', 'voice/*/status',
            'mcp', 'mcp/dev', 'mcp/storefront', 'mcp/catalog', 'mcp/customer',
            'api/v1/*', 'docs/feedback', 'help-center/ask',
            'csp-report', 'csp-report/payment/*', 'folio-metrics/*', 'dash/client-error',
            'campaigns/unsubscribe/*', // P6-222: signed one-click unsubscribe (RFC 8058)
            'campaigns/merchant/unsubscribe/*', // RFC 8058 one-click POST (signed URL, no session)
            'auth/social/customer/apple/callback',
        ], $except);
    }
}
