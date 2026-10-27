<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Free public tool: /tools/qr-menu-generator (SEO run W6.1).
 *
 * Page + the server-side render endpoint that reuses QrRenderService
 * (bacon/bacon-qr-code — no new dependency, no DB writes). HTTP-through the
 * controller; asserts tool markers, the KEYWORDS §Generator title, and that
 * the render endpoint encodes a payload, offers downloads, and 422s (never
 * 500s) on bad input so the route sweep stays green.
 */
class QrMenuGeneratorPageTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_page_renders_with_tool_markers(): void
    {
        $response = $this->get(route('tools.qr-generator'));

        $response->assertOk();
        $response->assertSee(___('Free QR Code Menu Generator'));
        $response->assertSeeHtml('data-qr-root');
        $response->assertSeeHtml('data-qr-endpoint');
        $response->assertSeeHtml('qr-menu-generator.js');
        $response->assertSeeHtml('data-qr-color');
        $response->assertSee(___('Download PNG'));
        $response->assertSee(___('Download SVG'));

        $html = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('noindex', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
    }

    public function test_title_matches_keywords_generator_payload(): void
    {
        // Retitled 2026-09 from GSC: the page is shown for "qr menu maker",
        // "qr code menu generator" and "qr menu creator" (KEYWORDS.md).
        $html = $this->get(route('tools.qr-generator'))->getContent();
        $this->assertStringContainsString(
            '<title>Free QR Menu Maker &amp; QR Code Menu Generator | ',
            $html
        );
    }

    public function test_page_ships_faq_and_web_application_schema(): void
    {
        $html = (string) $this->get(route('tools.qr-generator'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $types = array_map(fn ($j) => json_decode($j, true)['@type'] ?? 'INVALID', $m[1]);
        $this->assertContains('FAQPage', $types);
        $this->assertContains('WebApplication', $types);
        $this->assertNotContains('INVALID', $types);

        // The visible FAQ mirrors the schema, plus the print guide and comparison.
        $this->assertSame(7, substr_count($html, 'class="mk-faq__item"'));
        $this->assertStringContainsString(___('How to print your menu QR code'), $html);
        $this->assertStringContainsString(___('Static QR code or a digital menu?'), $html);
    }

    public function test_render_endpoint_encodes_svg(): void
    {
        $response = $this->get(route('tools.qr-generator.render', ['url' => 'https://menu.example.com', 'fg' => '#7c3aed']));

        $response->assertOk();
        $this->assertStringContainsString('image/svg+xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_render_endpoint_download_sets_attachment(): void
    {
        $response = $this->get(route('tools.qr-generator.render', ['url' => 'https://menu.example.com', 'format' => 'svg', 'download' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('menu-qr-code.svg', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_render_endpoint_png_or_svg_fallback(): void
    {
        $response = $this->get(route('tools.qr-generator.render', ['url' => 'https://menu.example.com', 'format' => 'png']));

        $response->assertOk();
        // PNG when GD is present; a clean SVG fallback when it is not.
        $ct = (string) $response->headers->get('Content-Type');
        $this->assertTrue(
            str_contains($ct, 'image/png') || str_contains($ct, 'image/svg+xml'),
            "Unexpected content type: $ct"
        );
    }

    public function test_render_endpoint_missing_url_is_422_not_500(): void
    {
        // The route sweep hits this param-free GET with no query — must be < 500.
        $this->get(route('tools.qr-generator.render'))->assertStatus(422);
    }

    public function test_ar_renders_rtl(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $html = $this->get(route('tools.qr-generator'))->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('مولّد QR مجاني للمنيو', $html);
    }

    public function test_localized_strings_seeded_for_all_locales(): void
    {
        foreach (['en', 'ar', 'de', 'es', 'fr', 'pt', 'ru', 'tr', 'zh', 'hi', 'ur'] as $locale) {
            $lang = require base_path("lang/$locale/lang.php");
            $this->assertArrayHasKey('menu_link_or_website_url', $lang, "[$locale] missing generator key");
            if ($locale !== 'en') {
                $this->assertNotSame('Menu link or website URL', $lang['menu_link_or_website_url'], "[$locale] left untranslated");
            }
        }
    }
}
