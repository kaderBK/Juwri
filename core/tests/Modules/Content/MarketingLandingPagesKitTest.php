<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WI-16f-2 / WI-16g: the landing pages (features, themes, WhatsApp ordering,
 * no-commission, country, compare, guide, industry) render on the owner-ui kit
 * frame with no bought-theme markup, imagery or Bootstrap, and keep their key
 * content and structured data.
 */
class MarketingLandingPagesKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function kitPage(string $url, bool $allowThemeShots = false): string
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('mk-body', $html, "$url: kit frame");
        $this->assertStringNotContainsString('mk-body--legacy', $html, "$url: no legacy body");
        $this->assertStringNotContainsString('bootstrap', strtolower($html), "$url: no Bootstrap");
        // The page body (<main>) may not point at the old theme. The theme
        // gallery thumbnails (theme_showcase_shot()) now live in assets/site/themes,
        // so no exemption is needed any more. (The frame head is checked by the frame tests.)
        $main = preg_match('#<main id="main"[^>]*>(.*)</main>#s', $html, $mm) ? $mm[1] : '';
        $this->assertNotSame('', trim($main), "$url: page content");
        $scan = $main;
        $at = strpos($scan, 'assets/templates/lamar');
        $this->assertFalse($at, "$url: no old-theme assets, found near: ".($at === false ? '' : substr($scan, max(0, $at - 120), 240)));

        return $html;
    }

    /** @return array<string,mixed> */
    private function jsonLdTypes(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertIsArray($decoded, 'A JSON-LD block failed to parse');
            $types[] = $decoded['@type'] ?? null;
        }

        return $types;
    }

    public function test_features_page(): void
    {
        $html = $this->kitPage(route('features'));

        $this->assertStringContainsString('id="f-qr-menu-builder"', $html);
        $this->assertStringContainsString('href="#storefront"', $html);
        $this->assertStringContainsString(e(___('Grill station')), $html);
    }

    public function test_themes_page_uses_the_helper_thumbnails(): void
    {
        $html = $this->kitPage(route('themes'), true);

        $this->assertStringContainsString('th-grid', $html);
        $shot = theme_showcase_shot('crave');
        if ($shot !== null) {
            $this->assertStringContainsString($shot, $html);
        }
    }

    public function test_whatsapp_landing_page(): void
    {
        $html = $this->kitPage(route('whatsapp.landing'));

        $this->assertStringContainsString(e(___('How WhatsApp ordering works')), $html);
        $this->assertContains('FAQPage', $this->jsonLdTypes($html));
    }

    public function test_no_commission_page(): void
    {
        $html = $this->kitPage(route('no-commission'));

        $this->assertStringContainsString('Yemeksepeti', $html);
        $this->assertContains('FAQPage', $this->jsonLdTypes($html));
    }

    public function test_country_page(): void
    {
        $html = $this->kitPage(route('country.landing', 'sa'));

        $this->assertStringContainsString('mk-faq__item', $html);
        $this->assertStringContainsString(route('register'), $html);
        $this->assertContains('FAQPage', $this->jsonLdTypes($html));
    }

    public function test_compare_page(): void
    {
        $html = $this->kitPage(route('compare.show', 'foodics'));

        $this->assertStringContainsString('class="mk-table"', $html);
        $this->assertStringContainsString('Foodics', $html);
        $this->assertContains('FAQPage', $this->jsonLdTypes($html));
    }

    public function test_guide_page(): void
    {
        $html = $this->kitPage(route('guides.show', 'zatca-einvoicing'));

        $this->assertStringContainsString('mk-prose', $html);
        $this->assertStringContainsString('Fatoora', $html);
        $this->assertContains('BreadcrumbList', $this->jsonLdTypes($html));
    }

    public function test_industry_page(): void
    {
        $html = $this->kitPage(route('industries.show', 'retail'));

        $this->assertStringContainsString('mk-card', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('industries.show', 'retail').'"', $html);
    }
}
