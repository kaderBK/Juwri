<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Support\Csp\StorefrontPolicy;
use Illuminate\Http\Response;
use Juwri\Core\Http\Middleware\AddCspHeaders;
use Tests\TestCase;

/**
 * P0-4: nonce-based CSP on the storefront, report-only until CSP_ENFORCE=true.
 */
class CspTest extends TestCase
{
    public function test_storefront_policy_is_nonce_based_and_has_no_unsafe_inline(): void
    {
        $policy = new StorefrontPolicy;
        $policy->reportOnly();
        $response = new Response('');
        $policy->applyTo($response);

        $header = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotEmpty($header, 'report-only header should be set');
        $this->assertStringContainsString("default-src 'self'", $header);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $header);
        $this->assertStringContainsString("object-src 'none'", $header);
        $this->assertStringContainsString("frame-ancestors 'self'", $header);
        $scriptDirective = collect(explode(';', $header))
            ->first(fn (string $directive): bool => str_starts_with(trim($directive), 'script-src '));
        $this->assertNotNull($scriptDirective);
        $this->assertStringNotContainsString('unsafe-inline', $scriptDirective);
        $this->assertStringNotContainsString('unsafe-eval', $header);
    }

    public function test_enforce_mode_uses_the_enforcing_header(): void
    {
        $policy = new StorefrontPolicy; // reportOnly defaults false = enforce
        $response = new Response('');
        $policy->applyTo($response);

        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_publicview_route_carries_the_csp_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'publicView');

        $this->assertNotNull($route, 'publicView route exists');
        $this->assertContains(AddCspHeaders::class, $route->gatherMiddleware());
    }

    public function test_default_is_enforcing_on_storefront_and_dashboard(): void
    {
        // P4-17 follow-up: with the env unset, the storefront policy is ENFORCED on every storefront page and the
        // dashboard policy too; the env switches remain only as a roll-back.
        $this->assertSame(StorefrontPolicy::class, config('csp.policy'));
        $this->assertSame('', (string) config('csp.report_only_policy'));
        $this->assertTrue((bool) config('csp.enforce_dashboard'));
        // the shipped defaults (a local .env may still carry the old value)
        $src = (string) file_get_contents(config_path('csp.php'));
        $this->assertStringContainsString("env('CSP_STOREFRONT_ENFORCE', 'all')", $src);
        $this->assertStringContainsString("env('CSP_ENFORCE_DASHBOARD', true)", $src);
        $this->assertMatchesRegularExpression('/^CSP_STOREFRONT_ENFORCE=all$/m', (string) file_get_contents(base_path('.env.example')));
    }
}
