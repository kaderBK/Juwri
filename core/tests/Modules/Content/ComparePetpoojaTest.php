<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W4.4 — Petpooja comparison page (English, India). Honest,
 * category-aware comparison (POS suite vs QR-menu/WhatsApp ordering).
 */
class ComparePetpoojaTest extends TestCase
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

    public function test_petpooja_page_renders(): void
    {
        $this->assertContains('petpooja', CompareDefs::slugs());

        $response = $this->get(route('compare.show', 'petpooja'));

        $response->assertOk();
        $response->assertSee('Petpooja Alternative for Restaurants (2026)');
        $response->assertSee('Petpooja');
        $response->assertSee('No — pricing on request');
        // Honest, category-aware: Petpooja's GST-billing strength is acknowledged.
        $response->assertSee('GST billing (India)');

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'petpooja').'"', $html);
    }

    public function test_petpooja_emits_valid_structured_data(): void
    {
        $html = $this->get(route('compare.show', 'petpooja'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
            if (isset($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('FAQPage', $types);
    }
}
