<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AisleStore;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/** P6-305: a product page names the product in its head (title, canonical, share image, og:type, Product JSON-LD). */
class KitProductHeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        AisleStore::make();
    }

    public function test_the_product_page_head_is_the_products_own(): void
    {
        $html = $this->get('/dune-and-co-demo?item='.ApiFixtures::SHIRT)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<title>Relaxed linen shirt \| [^<]+</title>#', $html);
        $this->assertStringNotContainsString('<title>'.___('the_qr_menu_for'), $html, 'no platform phrase on a goods shop');
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]+/dune-and-co-demo\?item='.ApiFixtures::SHIRT.'">#', $html, 'canonical is the item URL');
        $this->assertMatchesRegularExpression('#<meta property="og:url" content="[^"]+\?item='.ApiFixtures::SHIRT.'">#', $html);
        $this->assertStringContainsString('<meta property="og:type" content="product">', $html);
        $this->assertStringContainsString('<meta property="og:site_name"', $html);
        $this->assertMatchesRegularExpression('#<script type="application/ld\+json"[^>]*>(?=[^<]*"@type":"Product")(?=[^<]*"priceCurrency":"SAR")(?=[^<]*"price":"139\.00")#', $html);
    }

    public function test_the_store_home_keeps_the_store_head(): void
    {
        $html = $this->get('/dune-and-co-demo')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"?]+/dune-and-co-demo">#', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringNotContainsString('"@type":"Product"', $html);
    }
}
