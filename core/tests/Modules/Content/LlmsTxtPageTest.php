<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W6.3 (SEO run): the /llms.txt GEO surface already existed
 * (DeveloperDocsController@llms, route name "llms"). This covers the W6.3
 * enhancements layered onto it — the free tools, feature/theme pages, pricing
 * model, e-invoicing compliance list, quotable facts and the agent-discovery
 * manifests — plus the plain-text / size contract. The pre-existing marketing +
 * API reference is guarded by SeoLlmsTxtTest.
 */
class LlmsTxtPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);
    }

    public function test_llms_txt_is_plain_text_under_10kb(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertLessThan(10240, strlen($body), 'llms.txt must stay under 10KB');
        $this->assertStringNotContainsStringIgnoringCase('<html', $body);
        $this->assertStringNotContainsStringIgnoringCase('<!doctype', $body);
    }

    public function test_llms_txt_lists_the_free_tools_and_marketing_pages(): void
    {
        $body = $this->get('/llms.txt')->getContent();

        $this->assertStringContainsString('/tools/commission-calculator', $body);
        $this->assertStringContainsString('/tools/qr-menu-generator', $body);
        $this->assertStringContainsString('/tools/menu-scanner', $body);
        $this->assertStringContainsString(rtrim(route('features'), '/'), $body);
        $this->assertStringContainsString(rtrim(route('themes'), '/'), $body);
    }

    public function test_llms_txt_states_pricing_model_and_compliance(): void
    {
        $body = $this->get('/llms.txt')->getContent();

        $this->assertStringContainsString('## Pricing model', $body);
        $this->assertStringContainsString('0% commission', $body);
        $this->assertStringContainsString('## E-invoicing compliance', $body);
        $this->assertStringContainsString('ZATCA', $body);
        $this->assertStringContainsString('JoFotara', $body);
        $this->assertStringContainsString('ETA', $body);
        $this->assertStringContainsString('## Quotable facts', $body);
        $this->assertStringContainsString('27 payment gateways', $body);
    }

    public function test_llms_txt_links_agent_discovery_manifests(): void
    {
        $body = $this->get('/llms.txt')->getContent();

        $this->assertStringContainsString('/mcp', $body);
        $this->assertStringContainsString('/.well-known/mcp.json', $body);
        $this->assertStringContainsString('/.well-known/ucp', $body);
    }

    public function test_llms_txt_route_is_served_at_the_root(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/llms.txt', 'GET')
        );
        $this->assertSame('llms', $route->getName());
    }
}
