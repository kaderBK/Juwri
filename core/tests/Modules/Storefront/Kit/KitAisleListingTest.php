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

/**
 * P3-08 (aisle §6): the store page lists the catalogue on the server with the headless API's engine — facet filters, stock and
 * price filters (the page's inputs are major units of the store currency), sort, an echoed query — and the wishlist page shows
 * what a guest saved.
 */
class KitAisleListingTest extends TestCase
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

    /** @return list<string> the item ids of the product cards, in page order */
    private function cards(string $query = ''): array
    {
        $html = $this->get('/dune-and-co-demo'.$query)->assertOk()->getContent();
        preg_match_all('/<li class="c-pcard[^"]*" data-item="([0-9a-f-]{36})"/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_both_products_are_listed_by_default(): void
    {
        $this->assertEqualsCanonicalizing([ApiFixtures::SHIRT, ApiFixtures::TROUSERS], $this->cards());
    }

    public function test_sort_by_price_orders_the_cards(): void
    {
        $this->assertSame([ApiFixtures::SHIRT, ApiFixtures::TROUSERS], $this->cards('?sort=price_asc'));
        $this->assertSame([ApiFixtures::TROUSERS, ApiFixtures::SHIRT], $this->cards('?sort=price_desc'));
    }

    public function test_an_unknown_sort_and_a_stale_cursor_fall_back_to_the_first_page(): void
    {
        $this->assertCount(2, $this->cards('?sort=bogus&cursor=not-a-real-cursor'));
    }

    public function test_the_price_inputs_are_major_units_of_the_store_currency(): void
    {
        // SAR 139 and SAR 229: 150 keeps only the trousers, 200 keeps only the shirt
        $this->assertSame([ApiFixtures::TROUSERS], $this->cards('?price_min=150'));
        $this->assertSame([ApiFixtures::SHIRT], $this->cards('?price_max=200'));
        $html = $this->get('/dune-and-co-demo?price_min=150')->assertOk()->getContent();
        $this->assertStringContainsString('value="150"', $html, 'the page echoes what the shopper typed');
    }

    public function test_a_facet_filter_keeps_only_matching_products(): void
    {
        // the shirt has Colour and Size; the trousers only Navy / M
        $both = $this->cards('?filter[colour][]=navy');
        $this->assertSame([ApiFixtures::TROUSERS], $both);
        $this->assertSame([ApiFixtures::SHIRT], $this->cards('?filter[colour][]=sand'));
    }

    public function test_a_filter_that_matches_nothing_shows_no_cards_and_the_page_still_renders(): void
    {
        $this->assertSame([], $this->cards('?filter[colour][]=purple'));
    }

    public function test_the_wishlist_page_lists_what_a_guest_saved(): void
    {
        $add = $this->postJson('/dune-and-co-demo/wishlist', ['item_id' => ApiFixtures::TROUSERS, 'variant_id' => ApiFixtures::TROUSERS_NAVY_M])->assertCreated();
        $cookie = collect($add->headers->getCookies())->first(fn ($c) => str_contains($c->getName(), 'wish'));
        $this->assertNotNull($cookie, 'the guest list is kept by a cookie');

        $page = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/dune-and-co-demo/wishlist', ['Accept' => 'text/html'])->assertOk()->getContent();
        $this->assertStringContainsString('Wide-leg trousers', $page);
        $this->assertStringNotContainsString('Relaxed linen shirt', $page);
        $this->assertStringNotContainsString('Your wishlist is empty', $page);
        $this->assertStringContainsString('data-kit-wish-move', $page, '"Move to bag" on a saved card');
    }

    public function test_the_wishlist_of_one_guest_is_not_another_guests(): void
    {
        $this->postJson('/dune-and-co-demo/wishlist', ['item_id' => ApiFixtures::TROUSERS])->assertCreated();
        $other = $this->get('/dune-and-co-demo/wishlist', ['Accept' => 'text/html'])->assertOk()->getContent();
        $this->assertStringContainsString('Your wishlist is empty', $other);
    }
}
