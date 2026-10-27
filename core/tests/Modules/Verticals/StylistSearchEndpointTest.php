<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * rev/multi-vertical — the maison (clothing) theme's AI Style Search + Visual
 * Search endpoints. Both delegate to the bound StylistProvider (mock today —
 * matches against the store's OWN catalog) and always return {items:[<menu id>]}.
 * These guard the JSON contract + store-scoping so a real provider swap keeps
 * the theme's contract.
 */
class StylistSearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function clothingStore(): Store
    {
        $owner = User::factory()->create();

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'clothing']);
    }

    public function test_text_search_returns_matching_catalog_item_ids(): void
    {
        $store = $this->clothingStore();
        $jacket = Menu::create(['store_id' => $store->id, 'name' => 'Denim jacket', 'description' => 'rugged denim', 'price' => 80, 'active' => 1]);
        $shoes = Menu::create(['store_id' => $store->id, 'name' => 'Leather boots', 'price' => 120, 'active' => 1]);

        $res = $this->postJson(route('stylist.search', ['slug' => $store->slug]), ['query' => 'denim']);

        $res->assertOk()->assertJsonStructure(['items']);
        $items = $res->json('items');
        // The denim query matches the jacket; every id belongs to THIS store.
        $this->assertContains($jacket->id, $items);
        foreach ($items as $id) {
            $this->assertContains($id, [$jacket->id, $shoes->id]);
        }
    }

    public function test_text_search_falls_back_to_a_curated_sample_when_nothing_matches(): void
    {
        $store = $this->clothingStore();
        $a = Menu::create(['store_id' => $store->id, 'name' => 'Scarf', 'price' => 20, 'active' => 1]);

        $res = $this->postJson(route('stylist.search', ['slug' => $store->slug]), ['query' => 'zzzznomatch']);

        $res->assertOk();
        // No keyword match → a curated sample of the catalog, not an empty list.
        $this->assertSame([$a->id], $res->json('items'));
    }

    public function test_visual_search_returns_store_scoped_ids_for_an_upload(): void
    {
        $store = $this->clothingStore();
        $a = Menu::create(['store_id' => $store->id, 'name' => 'Coat', 'price' => 150, 'active' => 1]);

        $res = $this->postJson(route('visual.search', ['slug' => $store->slug]), [
            'photo' => UploadedFile::fake()->create('look.jpg', 12),
        ]);

        $res->assertOk()->assertJsonStructure(['items']);
        foreach ($res->json('items') as $id) {
            $this->assertSame($a->id, $id);
        }
    }

    public function test_search_ids_never_leak_another_stores_catalog(): void
    {
        $storeA = $this->clothingStore();
        $storeB = $this->clothingStore();
        $foreign = Menu::create(['store_id' => $storeB->id, 'name' => 'Denim jacket', 'price' => 80, 'active' => 1]);

        $items = $this->postJson(route('stylist.search', ['slug' => $storeA->slug]), ['query' => 'denim'])->json('items');

        $this->assertNotContains($foreign->id, $items);
    }
}
