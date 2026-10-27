<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuEngineeringService;
use Tests\TestCase;

/**
 * Analytics SA1 — menu-engineering matrix: popularity × margin quadrants,
 * view-to-order ratio, underperformer flag, restaurant-scoped.
 */
class MenuEngineeringTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function menu(Store $r, MenuCategory $c, float $price, ?float $cost): Menu
    {
        return Menu::create(['store_id' => $r->id, 'category_id' => $c->id, 'name' => 'I'.Str::random(4), 'price' => $price, 'cost' => $cost, 'active' => '1']);
    }

    private function orderQty(Store $r, Menu $m, int $qty): void
    {
        $o = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'created_at' => now()]);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $m->id, 'quantity' => $qty, 'variation' => 0]);
    }

    private function views(Store $r, Menu $m, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('menu_item_events')->insert(['menu_id' => $m->id, 'store_id' => $r->id, 'type' => 'view', 'created_at' => now()]);
        }
    }

    public function test_classifies_four_quadrants(): void
    {
        $r = $this->storeFixture();
        $c = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $star = $this->menu($r, $c, 20, 12);   // margin 8, ordered 10
        $plow = $this->menu($r, $c, 20, 18);   // margin 2, ordered 10
        $puzz = $this->menu($r, $c, 20, 12);   // margin 8, ordered 1
        $dog = $this->menu($r, $c, 20, 18);   // margin 2, ordered 1

        $this->orderQty($r, $star, 10);
        $this->orderQty($r, $plow, 10);
        $this->orderQty($r, $puzz, 1);
        $this->orderQty($r, $dog, 1);

        $items = collect(MenuEngineeringService::matrix($r->id)['items'])->keyBy('menu_id');
        $this->assertSame('star', $items[$star->id]['quadrant']);
        $this->assertSame('plowhorse', $items[$plow->id]['quadrant']);
        $this->assertSame('puzzle', $items[$puzz->id]['quadrant']);
        $this->assertSame('dog', $items[$dog->id]['quadrant']);
    }

    public function test_view_to_order_and_underperformer_flag(): void
    {
        $r = $this->storeFixture();
        $c = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $m = $this->menu($r, $c, 20, 10);
        $this->orderQty($r, $m, 1);   // 1 order
        $this->views($r, $m, 20);     // 20 views → 5% view-to-order → underperformer

        $item = collect(MenuEngineeringService::matrix($r->id)['items'])->firstWhere('menu_id', $m->id);
        $this->assertSame(5.0, $item['view_to_order']);
        $this->assertTrue($item['underperformer']);
    }

    public function test_degrades_to_popularity_only_without_cost(): void
    {
        $r = $this->storeFixture();
        $c = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $m = $this->menu($r, $c, 20, null); // no cost
        $this->orderQty($r, $m, 5);

        $res = MenuEngineeringService::matrix($r->id);
        $this->assertFalse($res['has_margin']);
        $this->assertContains($res['items'][0]['quadrant'], ['popular', 'unpopular']);
    }
}
