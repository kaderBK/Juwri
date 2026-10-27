<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-17 part 2c — characterisation of today's server-side menu item search, pinned before the switch to Scout.
 *
 * The only server-side menu item search a store user reaches is the owner dashboard command palette
 * (DashboardController::search, `items` group): the item NAME (not the description) matched as a case-insensitive
 * substring, scoped to the owner's stores (or the selected store), soft-deleted items left out, then ranked in PHP.
 * The storefront, POS register and kiosk menu searches filter the already-loaded menu payload in the browser, and
 * API v1 has no item search parameter — none of them query the database with the search term.
 */
class MenuSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);

        return [$owner, $store];
    }

    private function item(Store $store, string $name, string $description = 'd'): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'C'], ['parent' => null, 'position' => 1]);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'description' => $description,
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
    }

    /** @return list<array{label: string, url: string}> */
    private function itemsGroup(User $owner, string $q): array
    {
        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => $q]))->assertOk()->json();
        $group = collect($json['groups'])->firstWhere('key', 'items');

        return $group['items'] ?? [];
    }

    public function test_name_matches_as_a_case_insensitive_substring(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $hit = $this->item($store, 'Margherita Pizza');
        $this->item($store, 'Lentil Soup');

        $items = $this->itemsGroup($owner, 'GHERITA');

        $this->assertSame(['Margherita Pizza'], array_column($items, 'label'));
        $this->assertStringContainsString('?item='.$hit->id, $items[0]['url']);
    }

    public function test_description_is_not_searched(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->item($store, 'Lentil Soup', 'with saffron');
        $this->item($store, 'Saffron Rice');

        $this->assertSame(['Saffron Rice'], array_column($this->itemsGroup($owner, 'saffron'), 'label'));
    }

    public function test_soft_deleted_items_are_left_out(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->item($store, 'Falafel Wrap');
        $this->item($store, 'Falafel Plate')->delete();

        $this->assertSame(['Falafel Wrap'], array_column($this->itemsGroup($owner, 'falafel'), 'label'));
    }

    public function test_a_store_never_sees_another_stores_items_that_share_the_words(): void
    {
        [$ownerA, $storeA] = $this->ownerWithStore();
        [$ownerB, $storeB] = $this->ownerWithStore();
        $mine = $this->item($storeA, 'Chicken Shawarma');
        $theirs = $this->item($storeB, 'Chicken Shawarma');
        $this->item($storeB, 'Shawarma Chicken Plate');

        $items = $this->itemsGroup($ownerA, 'shawarma');

        $this->assertCount(1, $items);
        $this->assertStringContainsString('?item='.$mine->id, $items[0]['url']);
        $this->assertStringNotContainsString('?item='.$theirs->id, json_encode($items));
    }

    public function test_an_arabic_word_finds_the_arabic_item_name(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->item($store, 'شاورما دجاج');
        $this->item($store, 'فلافل');

        $this->assertSame(['شاورما دجاج'], array_column($this->itemsGroup($owner, 'دجاج'), 'label'));
    }

    public function test_ranking_puts_the_exact_name_first(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->item($store, 'Deluxe Burger Box');
        $this->item($store, 'Burger');

        $this->assertSame(['Burger', 'Deluxe Burger Box'], array_column($this->itemsGroup($owner, 'burger'), 'label'));
    }
}
