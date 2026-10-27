<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\GuideDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W5.2 — compliance-guide pages (guides/{slug}).
 *
 * Rendered HTTP-through-controller (blade-direct render() misses the controller
 * contract). Tests the real shipped guides (ZATCA/JoFotara/ETA), the bilingual
 * en/ar content switch, valid BreadcrumbList + FAQPage JSON-LD, canonical, the
 * no-dead-utility guard, and the defs whitelist (unknown slug 404s).
 */
class GuidePageTest extends TestCase
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
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_zatca_guide_renders_in_english(): void
    {
        $response = $this->get(route('guides.show', 'zatca-einvoicing'));

        $response->assertOk();
        $response->assertSee('ZATCA E-Invoicing Guide for Restaurants in Saudi Arabia (2026)');
        $response->assertSee('Fatoora');
        $response->assertSee('The QR code on every receipt');

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('mk-prose', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('guides.show', 'zatca-einvoicing').'"', $html);
    }

    public function test_zatca_guide_renders_arabic_content_for_ar_locale(): void
    {
        app()->setLocale('ar');

        $response = $this->get(route('guides.show', 'zatca-einvoicing'));

        $response->assertOk();
        // Canonical AR H1 (KEYWORDS §Compliance) — verbatim.
        $response->assertSee('دليل الفاتورة الإلكترونية للمطاعم في السعودية 2026');
        // Not the English H1.
        $response->assertDontSee('ZATCA E-Invoicing Guide for Restaurants in Saudi Arabia (2026)');
    }

    public function test_all_three_guides_are_whitelisted_and_render(): void
    {
        $slugs = GuideDefs::slugs();
        foreach (['zatca-einvoicing', 'jofotara-einvoicing', 'eta-einvoicing'] as $slug) {
            $this->assertContains($slug, $slugs, "guide {$slug} not whitelisted");
            $this->get(route('guides.show', $slug))->assertOk();
        }

        // JoFotara's quotable exemption (KEYWORDS §Countries jo) must be present.
        $this->get(route('guides.show', 'jofotara-einvoicing'))->assertSee('75,000 JOD');
    }

    public function test_guide_emits_valid_structured_data(): void
    {
        $html = $this->get(route('guides.show', 'eta-einvoicing'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Invalid JSON-LD: {$json}");
            if (isset($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('FAQPage', $types);
    }

    public function test_unknown_guide_slug_404s(): void
    {
        $this->get(route('guides.show', 'not-a-real-guide'))->assertNotFound();
        $this->assertNull(GuideDefs::find('../../etc/passwd'));
    }
}
