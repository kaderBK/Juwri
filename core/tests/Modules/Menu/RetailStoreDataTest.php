<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\StoreCloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\SizeGuide;
use Juwri\Modules\Menu\Models\StockAlert;
use Juwri\Modules\Menu\Models\WishlistItem;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08 — the retail tables in the store-data registry (duplicate / transfer):
 *
 *  - a duplicated store's variant cells name the CLONE's option groups (regression: the cell keys — `options`
 *    {group id: option index} — kept the source store's group ids, so the copy's matrix pointed at the source);
 *  - size guides and shipping zones / methods are catalogue (copied with fresh public ids, references remapped; a
 *    pickup branch is not carried over);
 *  - wishlists, back-in-stock requests and returns are the shoppers' data (never copied).
 */
class RetailStoreDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_a_duplicate_keeps_the_variant_matrix_on_its_own_groups_and_copies_the_retail_catalogue(): void
    {
        $source = (new ApiFixtures)->duneRetail();
        $shirt = Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail();
        $guide = SizeGuide::create(['store_id' => $source->id, 'title' => 'Shirts', 'unit' => 'cm', 'columns' => ['Size'], 'rows' => [['M']], 'active' => true]);
        $shirt->forceFill(['size_guide_id' => $guide->id])->save();
        WishlistItem::create(['store_id' => $source->id, 'guest_key' => str_repeat('a', 64), 'menu_id' => $shirt->id]);
        StockAlert::create(['store_id' => $source->id, 'menu_id' => $shirt->id, 'channel' => 'email', 'contact' => 'a@example.com',
            'contact_hash' => str_repeat('b', 64), 'target' => 'm'.$shirt->id.':v0', 'status' => 'waiting', 'opted_in_at' => now()]);

        ['clone' => $clone] = app(StoreCloneService::class)->clone($source);

        $cloneShirt = DB::table('items')->where('store_id', $clone->id)->where('name', 'Relaxed linen shirt')->first();
        $this->assertNotNull($cloneShirt);
        $cloneGroups = DB::table('modifier_groups')->where('store_id', $clone->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sourceGroups = DB::table('modifier_groups')->where('store_id', $source->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cells = DB::table('menu_variants')->where('menu_id', $cloneShirt->id)->pluck('options');
        $this->assertCount(5, $cells);
        foreach ($cells as $json) {
            $keys = array_map('intval', array_keys((array) json_decode((string) $json, true)));
            $this->assertCount(2, $keys);
            foreach ($keys as $key) {
                $this->assertContains($key, $cloneGroups, 'a cell names the clone\'s group');
                $this->assertNotContains($key, $sourceGroups, 'never the source store\'s group');
            }
        }

        $cloneGuide = DB::table('size_guides')->where('store_id', $clone->id)->first();
        $this->assertNotNull($cloneGuide);
        $this->assertNotSame((string) $guide->public_id, (string) $cloneGuide->public_id);
        $this->assertSame((int) $cloneGuide->id, (int) $cloneShirt->size_guide_id);

        $zone = DB::table('shipping_zones')->where('store_id', $clone->id)->first();
        $this->assertNotNull($zone);
        $methods = DB::table('shipping_methods')->where('store_id', $clone->id)->get();
        $this->assertCount(3, $methods);
        foreach ($methods as $method) {
            $this->assertNotContains((string) $method->public_id, [ApiFixtures::SHIP_STANDARD, ApiFixtures::SHIP_EXPRESS, ApiFixtures::SHIP_PICKUP]);
            $this->assertNull($method->pickup_branch_id, 'the source\'s branch is not the clone\'s');
            if ($method->shipping_zone_id !== null) {
                $this->assertSame((int) $zone->id, (int) $method->shipping_zone_id);
            }
        }

        $this->assertSame(0, DB::table('wishlist_items')->where('store_id', $clone->id)->count());
        $this->assertSame(0, DB::table('stock_alerts')->where('store_id', $clone->id)->count());
        $this->assertSame(1, DB::table('wishlist_items')->where('store_id', $source->id)->count(), 'the source keeps its rows');
    }
}
