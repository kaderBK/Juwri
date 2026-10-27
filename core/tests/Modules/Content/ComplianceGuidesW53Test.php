<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\GuideDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W5.3 — e-Adisyon (TR) + FBR (PK/EN) + Verifactu (ES) guides,
 * served through the W5.2 guides/{slug} template. Single-locale guides; also
 * covers GuideDefs::localize() first-block fallback.
 */
class ComplianceGuidesW53Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        foreach ([['en', 'English', 'ltr'], ['tr', 'Turkish', 'ltr'], ['es', 'Spanish', 'ltr'], ['ar', 'Arabic', 'rtl']] as [$code, $name, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => $dir, 'active' => 1]);
        }

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_all_three_guides_are_whitelisted(): void
    {
        $slugs = GuideDefs::slugs();
        foreach (['e-adisyon', 'fbr-pos', 'verifactu'] as $slug) {
            $this->assertContains($slug, $slugs, "guide {$slug} not whitelisted");
        }
    }

    public function test_e_adisyon_renders_turkish_with_penalty_hook(): void
    {
        app()->setLocale('tr');
        $response = $this->get(route('guides.show', 'e-adisyon'));

        $response->assertOk();
        $response->assertSee('e-Adisyon Nedir? Restoranlar için 2026 Rehberi');
        $response->assertSee('17.000 TL'); // verified GİB per-document penalty hook

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('guides.show', 'e-adisyon').'"', $html);
    }

    public function test_fbr_pos_renders_english_with_tier1_scope(): void
    {
        $response = $this->get(route('guides.show', 'fbr-pos'));

        $response->assertOk();
        $response->assertSee('FBR Integrated POS for Restaurants — 2026 Guide');
        $response->assertSee('Tier-1');
        $response->assertSee('Tax Asaan');
    }

    public function test_verifactu_renders_spanish_with_calendar(): void
    {
        app()->setLocale('es');
        $response = $this->get(route('guides.show', 'verifactu'));

        $response->assertOk();
        $response->assertSee('Verifactu para restaurantes: guía 2026');
        $response->assertSee('VERI*FACTU');
    }

    public function test_single_locale_guide_falls_back_not_empty(): void
    {
        // e-adisyon has only a `tr` block; at en it must fall back, not render empty.
        app()->setLocale('en');
        $response = $this->get(route('guides.show', 'e-adisyon'));

        $response->assertOk();
        $response->assertSee('e-Adisyon Nedir? Restoranlar için 2026 Rehberi');
    }

    public function test_guides_emit_valid_faq_schema(): void
    {
        foreach (['e-adisyon', 'fbr-pos', 'verifactu'] as $slug) {
            $html = $this->get(route('guides.show', $slug))->getContent();
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $types = [];
            foreach ($m[1] as $json) {
                $decoded = json_decode(trim($json), true);
                $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Invalid JSON-LD on {$slug}");
                if (isset($decoded['@type'])) {
                    $types[] = $decoded['@type'];
                }
            }
            $this->assertContains('BreadcrumbList', $types, "{$slug} missing BreadcrumbList");
            $this->assertContains('FAQPage', $types, "{$slug} missing FAQPage");
        }
    }
}
