<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BasketStore;
use Tests\TestCase;

/** P3-15: a grocery store with a large catalogue (2,000 products) must not render the whole catalogue into one page. */
class KitBasketLargeCatalogueTest extends TestCase
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
        $this->travelTo(BasketStore::clock());
    }

    /** @return array{0: BasketStore, 1: list<MenuCategory>} */
    private function bigStore(int $n = 2000): array
    {
        $shop = BasketStore::make();
        $cats = [MenuCategory::create(['store_id' => $shop->store->id, 'name' => 'Pantry', 'active' => 1]), MenuCategory::create(['store_id' => $shop->store->id, 'name' => 'Frozen', 'active' => 1])];
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['store_id' => $shop->store->id, 'category_id' => $cats[$i % 2]->id, 'name' => 'Item '.$i, 'price_minor' => (5 + ($i % 50)) * 100, 'currency_code' => 'SAR', 'active' => 1, 'public_id' => (string) Str::uuid7()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('items')->insert($chunk);
        }

        return [$shop, $cats];
    }

    public function test_a_two_thousand_product_store_sends_one_page_of_cards_with_load_more(): void
    {
        $this->bigStore();
        $html = $this->get('/fresh-mart-demo')->assertOk()->getContent();

        $this->assertLessThanOrEqual(48, substr_count($html, 'c-pcard"'));
        $this->assertGreaterThan(40, substr_count($html, 'c-pcard"'));
        $this->assertLessThan(400_000, strlen($html), 'one page of cards, not the catalogue');
        $this->assertStringContainsString('data-bk-load-more', $html);
        $this->assertStringContainsString('data-bk-sort-server', $html);
        $this->assertStringNotContainsString('data-kit-search', $html, 'the server searches a large catalogue');
        $this->assertMatchesRegularExpression('/Showing 48 of 20\d\d/', $html);
    }

    public function test_load_more_answers_the_next_page_of_cards_and_a_search_a_category_and_a_sort_are_server_side(): void
    {
        [$shop, $cats] = $this->bigStore();
        $first = $this->get('/fresh-mart-demo')->getContent();
        preg_match('/data-bk-load-more[^>]*>/', $first);
        preg_match('/href="([^"]+)" data-bk-load-more/', $first, $m);
        $next = html_entity_decode($m[1]);

        $page2 = $this->get($next.'&fragment=grid')->assertOk()->getContent();
        $this->assertSame(48, substr_count($page2, 'c-pcard"'));
        $this->assertStringContainsString('c-pgrid__next', $page2);
        $this->assertStringNotContainsString('<html', $page2, 'a fragment, not a page');

        $search = $this->get('/fresh-mart-demo?q=Item%201999')->assertOk()->getContent();
        $this->assertStringContainsString('Item 1999', $search);
        $this->assertLessThan(10, substr_count($search, 'c-pcard"'));

        $frozen = $this->get('/fresh-mart-demo?category='.$cats[1]->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('Frozen', $frozen);
        $this->assertStringNotContainsString('Item 0<', $frozen, 'Item 0 is a Pantry product');

        $dear = $this->get('/fresh-mart-demo?sort=price_desc')->assertOk()->getContent();
        preg_match('/c-pcard__name.*?<\/h3>/s', $dear, $first);
        $this->assertNotFalse(preg_match('/Item (\d+)/', $first[0], $n));
        $this->assertSame(54, 5 + ((int) $n[1] % 50), 'the dearest products come first');
    }

    public function test_a_small_catalogue_is_still_drawn_whole_with_the_client_side_search(): void
    {
        BasketStore::make();
        $html = $this->get('/fresh-mart-demo')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-bk-load-more', $html);
        $this->assertStringContainsString('data-kit-search', $html);
        $this->assertStringContainsString('data-bk-sort', $html);
        $this->assertStringNotContainsString('data-bk-sort-server', $html);
    }
}
