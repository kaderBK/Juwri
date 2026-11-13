<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLlmsTxtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);
    }

    public function test_llms_txt_has_marketing_summary_and_key_pages(): void
    {
        $response = $this->get('/llms.txt');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $body = $response->getContent();
        $this->assertStringContainsString('## What it does', $body);
        $this->assertStringContainsString('QR-code menu', $body);
        $this->assertStringContainsString('## Key pages', $body);
        $this->assertStringContainsString(rtrim(route('home'), '/'), $body);
    }

    public function test_llms_txt_keeps_the_api_reference(): void
    {
        $body = $this->get('/llms.txt')->assertOk()->getContent();

        // rev/seo R21 must not clobber the existing agent/API docs.
        $this->assertStringContainsString('API for AI agents', $body);
        $this->assertStringContainsString('/api/v1/storefront/menu', $body);
        $this->assertStringContainsString('Dev MCP', $body);
    }

    public function test_localized_home_links_only_in_prefix_mode(): void
    {
        Language::updateOrCreate(['code' => 'tr'], ['name' => 'TR', 'direction' => 'ltr', 'active' => 1]);

        config(['settings.include_language_code' => 0]);
        $this->assertStringNotContainsString('## Localized home pages', $this->get('/llms.txt')->getContent());

        config(['settings.include_language_code' => 1]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);
        $this->assertStringContainsString('## Localized home pages', $this->get('/llms.txt')->getContent());
    }
}
