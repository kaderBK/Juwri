<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Support\Csp\DashboardPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * S12 — CSP on the privileged surfaces. Enforced by default since the P4-17 follow-up; CSP_ENFORCE_DASHBOARD=false
 * puts it back to report-only (roll-back), independent of the storefront's CSP_ENFORCE.
 */
class DashboardCspHeadersTest extends TestCase
{
    private function apply(): Response
    {
        return new AddDashboardCspHeaders()->handle(
            Request::create('/admin/x', 'GET'),
            fn () => new Response('ok')
        );
    }

    public function test_the_roll_back_switch_makes_it_report_only(): void
    {
        config()->set('csp.enabled', true);
        config()->set('csp.enforce_dashboard', false);

        $res = $this->apply();

        $this->assertTrue($res->headers->has('Content-Security-Policy-Report-Only'), 'report-only header under the roll-back switch');
        $this->assertFalse($res->headers->has('Content-Security-Policy'), 'must NOT enforce under the roll-back switch');
        $this->assertStringContainsString("default-src 'self'", (string) $res->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_it_enforces_when_the_dashboard_flag_is_on(): void
    {
        config()->set('csp.enabled', true);
        config()->set('csp.enforce_dashboard', true);

        $res = $this->apply();

        $this->assertTrue($res->headers->has('Content-Security-Policy'), 'enforcing header must be present when flagged on');
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_it_is_inert_when_csp_disabled(): void
    {
        config()->set('csp.enabled', false);

        $res = $this->apply();

        $this->assertFalse($res->headers->has('Content-Security-Policy'));
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_policy_is_nonce_based(): void
    {
        // The policy nonces script/style so enforce mode can allow the app's own
        // inline tags once they carry nonce="{{ csp_nonce() }}".
        config()->set('csp.enabled', true);
        config()->set('csp.enforce_dashboard', false);
        app()->instance('csp-nonce', 'testnonce123');

        $header = (string) $this->apply()->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('script-src', $header);
        $this->assertStringContainsString("'nonce-testnonce123'", $header);
    }
}
