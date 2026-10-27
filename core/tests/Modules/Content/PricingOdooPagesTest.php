<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RB-10 — Odoo comparison page + /pricing add-on prices (bilingual en + ar).
 * The Odoo page reads its copy from resources/views/marketing/data/compare-defs/odoo.php;
 * the /pricing add-on figures come from config/pricing_page.php (PRICING.md §8/§8a).
 */
class PricingOdooPagesTest extends TestCase
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

    public function test_odoo_is_a_whitelisted_compare_slug(): void
    {
        $this->assertContains('odoo', CompareDefs::slugs());
    }

    public function test_odoo_page_renders_in_english(): void
    {
        $response = $this->get(route('compare.show', 'odoo'));

        $response->assertOk();
        $response->assertSee('Odoo Alternative for Restaurants (2026)');
        $response->assertSee('Odoo');
        $response->assertSee('Odoo connector');

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'odoo').'"', $html);
    }

    public function test_odoo_page_renders_arabic_for_ar_locale(): void
    {
        app()->setLocale('ar');

        $response = $this->get(route('compare.show', 'odoo'));

        $response->assertOk();
        $response->assertSee('بديل Odoo للمطاعم (2026)');
        $response->assertDontSee('Odoo Alternative for Restaurants (2026)');
    }

    public function test_pricing_page_shows_configured_addon_prices_in_english(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertOk();
        $response->assertSee('Optional add-ons');
        $response->assertSee('SAR 559');
        $response->assertSee('600 minutes included');
        $response->assertSee('SAR 19');
        $response->assertSee('SAR 29');

        $this->assertSame(559, config('pricing_page.addons.phone_agent.sar'));
        $this->assertSame(149, config('pricing_page.addons.phone_agent.usd'));

        $usd = (int) config('pricing_page.addons.phone_agent.usd');
        $this->assertSame((int) round($usd * 3.75), config('pricing_page.addons.phone_agent.sar'));

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
    }

    public function test_pricing_page_renders_translated_addons_in_arabic(): void
    {
        app()->setLocale('ar');

        $response = $this->get(route('pricing'));

        $response->assertOk();
        $response->assertSee('إضافات اختيارية');
        $response->assertSee('خدمة توقيع زاتكا');
        $response->assertDontSee('Optional add-ons');

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
    }
}
