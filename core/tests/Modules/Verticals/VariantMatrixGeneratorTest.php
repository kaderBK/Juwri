<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuVariant;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Services\Catalog\VariantMatrixGenerator;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane A2 — variant matrix + per-variant stock (DECISIONS A2).
 *
 * A size×color cartesian generates one row per cell; each cell's own stock_qty
 * decrements independently on order (and is restored on a POS "Edit order").
 */
class VariantMatrixGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $type = 'retail'): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_generate_a_size_by_color_matrix_creates_nine_cells(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'T-Shirt', 'price' => 25]);

        $result = app(VariantMatrixGenerator::class)->generate($item, [
            'Size' => ['S', 'M', 'L'],
            'Color' => ['Red', 'Blue', 'Green'],
        ]);

        $this->assertCount(9, $result['created']);
        $this->assertSame(9, MenuVariant::where('menu_id', $item->id)->count());
    }

    public function test_regenerating_does_not_duplicate_existing_cells(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'T-Shirt', 'price' => 25]);

        $gen = app(VariantMatrixGenerator::class);
        $gen->generate($item, ['Size' => ['S', 'M'], 'Color' => ['Red']]);
        $second = $gen->generate($item, ['Size' => ['S', 'M'], 'Color' => ['Red', 'Blue']]);

        // 2 existing (S/Red, M/Red) skipped, 2 new (S/Blue, M/Blue) created.
        $this->assertCount(2, $second['created']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(4, MenuVariant::where('menu_id', $item->id)->count());
    }

    public function test_stock_decrements_the_right_variant_on_order(): void
    {
        $store = $this->makeStore();
        $ownerId = (int) $store->user_id;
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'T-Shirt', 'price' => 25]);

        app(VariantMatrixGenerator::class)->generate($item, ['Size' => ['S', 'M'], 'Color' => ['Red', 'Blue']]);

        $sRed = MenuVariant::where('menu_id', $item->id)->get()->first(fn ($v) => $v->options->Size === 'S' && $v->options->Color === 'Red');
        $mBlue = MenuVariant::where('menu_id', $item->id)->get()->first(fn ($v) => $v->options->Size === 'M' && $v->options->Color === 'Blue');
        $sRed->update(['stock_qty' => 5, 'sku' => 'TS-S-RED']);
        $mBlue->update(['stock_qty' => 5, 'sku' => 'TS-M-BLU']);

        // NOTE: fulfillment-mode wiring into OrderApiService (ship/pickup_slot/…) is
        // Lane B's scope (F0-3/B1/B2) — this test stays on the existing 'takeaway'
        // literal so it exercises ONLY the Lane A concern (variant stock).
        $order = OrderApiService::create($ownerId, $store->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'variation' => $sRed->id, 'quantity' => 2]],
        ]);

        $this->assertSame(3, MenuVariant::find($sRed->id)->stock_qty, 'the SELECTED variant (S/Red) must decrement');
        $this->assertSame(5, MenuVariant::find($mBlue->id)->stock_qty, 'a DIFFERENT variant (M/Blue) must be untouched');

        // Editing the order away restores the exact cell that was deducted.
        OrderApiService::replaceItems($order, [['item_id' => $item->id, 'variation' => $mBlue->id, 'quantity' => 1]]);

        $this->assertSame(5, MenuVariant::find($sRed->id)->stock_qty, 'restored after the line was replaced');
        $this->assertSame(4, MenuVariant::find($mBlue->id)->stock_qty, 'the NEW line decremented');
    }
}
