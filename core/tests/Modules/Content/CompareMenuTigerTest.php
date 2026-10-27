<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W4.2 — MenuTiger comparison page (bilingual en + ar).
 *
 * Also covers the CompareDefs::present() locale resolution added for W4.2.
 */
class CompareMenuTigerTest extends TestCase
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
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_menutiger_page_renders_in_english(): void
    {
        $this->assertContains('menutiger', CompareDefs::slugs());

        $response = $this->get(route('compare.show', 'menutiger'));

        $response->assertOk();
        $response->assertSee('MenuTiger Alternative for Restaurants (2026)');
        $response->assertSee('MenuTiger');
        $response->assertSee('Advanced plan ($46/mo)'); // verified table cell

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'menutiger').'"', $html);
    }

    public function test_menutiger_page_renders_arabic_for_ar_locale(): void
    {
        app()->setLocale('ar');

        $response = $this->get(route('compare.show', 'menutiger'));

        $response->assertOk();
        $response->assertSee('بديل MenuTiger للمطاعم (2026)');
        $response->assertDontSee('MenuTiger Alternative for Restaurants (2026)');
    }

    public function test_menutiger_emits_valid_structured_data(): void
    {
        $html = $this->get(route('compare.show', 'menutiger'))->getContent();

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
