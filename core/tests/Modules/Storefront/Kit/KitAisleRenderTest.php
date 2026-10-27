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

/** P3-08: the `aisle` kit theme renders a real retail store: the filterable catalogue, the product page, the wishlist page. */
class KitAisleRenderTest extends TestCase
{
    use RefreshDatabase;

    private AisleStore $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shop = AisleStore::make();
    }

    public function test_the_store_page_renders_with_the_aisle_theme(): void
    {
        $html = $this->get('/dune-and-co-demo')->assertOk()->getContent();

        $this->assertStringContainsString('data-theme-slug="aisle"', $html);
        $this->assertStringContainsString('c-pgrid', $html);
        $this->assertStringContainsString('class="c-pcard', $html);
        $this->assertStringContainsString('?item='.ApiFixtures::SHIRT, $html, 'a product is a page of its own');
        $this->assertStringContainsString('data-kit-filters', $html);
        $this->assertStringContainsString('name="filter[size][]"', $html, 'the catalogue facets are the filters');
        $this->assertStringContainsString('name="filter[colour][]"', $html);
        $this->assertStringContainsString('id="cart"', $html, 'the bag is a dialog');
        $this->assertStringContainsString('data-desktop-cart="drawer"', $html);
    }

    public function test_the_product_page_is_a_page_of_its_own_with_the_variant_picker(): void
    {
        $html = $this->get('/dune-and-co-demo?item='.ApiFixtures::SHIRT)->assertOk()->getContent();

        $this->assertStringContainsString('class="c-pdp"', $html);
        $this->assertStringContainsString('c-pdp__name', $html);
        $this->assertStringContainsString('Relaxed linen shirt', $html);
        $this->assertStringContainsString('c-swatch', $html, 'colour swatches');
        $this->assertStringContainsString('c-size__input', $html, 'size pills');
        $this->assertStringContainsString('data-kit-variants', $html, 'the variants with their stock');
        $this->assertStringContainsString('Choose a size', $html);
        $this->assertDoesNotMatchRegularExpression('/data-label="(XS|S|M|L)" checked/', $html, 'a size is never chosen for the shopper');
    }

    public function test_a_shared_variant_link_opens_on_that_variant(): void
    {
        $html = $this->get('/dune-and-co-demo?item='.ApiFixtures::SHIRT.'&variant=RLS-SAN-M')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-label="M" checked/', $html);
        $this->assertMatchesRegularExpression('/data-label="Sand" checked/', $html);
    }

    public function test_the_wishlist_page_and_the_json_list_share_one_address(): void
    {
        $page = $this->get('/dune-and-co-demo/wishlist', ['Accept' => 'text/html'])->assertOk()->getContent();
        $this->assertStringContainsString('Your wishlist is empty', $page);

        $this->getJson('/dune-and-co-demo/wishlist')->assertOk()->assertJson(['items' => []]);
    }
}
