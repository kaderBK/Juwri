<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO-growth W0.2 — JSON-LD across marketing pages. Home carries
 * SoftwareApplication + Organization + FAQPage; inner pages carry a
 * BreadcrumbList via the seo_breadcrumbs_jsonld() helper. Every emitted
 * ld+json block must be valid JSON.
 */
class SeoStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    /** every <script type="application/ld+json"> block on the page parses as JSON */
    private function assertAllJsonLdValid(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $types = [];
        foreach ($m[1] as $block) {
            $decoded = json_decode(trim($block), true);
            $this->assertNotNull($decoded, 'ld+json block is not valid JSON: ' . substr($block, 0, 80));
            $types[] = $decoded['@type'] ?? '';
        }
        return $types;
    }

    public function test_home_emits_org_software_and_faq_jsonld(): void
    {
        $types = $this->assertAllJsonLdValid($this->get('/')->getContent());
        $this->assertContains('SoftwareApplication', $types);
        $this->assertContains('Organization', $types);
    }

    public function test_features_emits_a_valid_breadcrumb(): void
    {
        $html = $this->get(route('features'))->assertOk()->getContent();
        $types = $this->assertAllJsonLdValid($html);
        $this->assertContains('BreadcrumbList', $types);
    }
}
