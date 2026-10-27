<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Restock-on-cancel: an order deducts stock at creation, so cancel/delete/reject
 * must give it back — exactly once, and never for a held order that never deducted.
 */
class InventoryRestockTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $o): Store
    {
        $t = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $o->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function trackedItem(Store $r, int $stock): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10,
            'active' => '1', 'track_inventory' => 1, 'stock_qty' => $stock,
        ]);
    }

    private function orderFor(Store $r, Menu $item, int $qty, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $r->id, 'status' => 'pending', 'type' => 'dine_in',
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => now(),
        ], $overrides));
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $qty, 'variation' => 0]);

        return $order;
    }

    public function test_restore_returns_stock_clears_sold_out_and_is_idempotent(): void
    {
        $r = $this->makeRestaurant($this->makeOwner());
        $item = $this->trackedItem($r, 3);
        $order = $this->orderFor($r, $item, 3);
        Menu::deductStock($item->id, 3); // creation-time deduction → 0 + sold out

        $this->assertSame(0, (int) $item->fresh()->stock_qty);
        $this->assertTrue((bool) $item->fresh()->is_sold_out);

        PosService::restoreOrderStock($order);
        $item->refresh();
        $this->assertSame(3, (int) $item->stock_qty, 'stock returned');
        $this->assertFalse((bool) $item->is_sold_out, 'sold-out cleared');
        $this->assertNotNull($order->fresh()->stock_restored_at);

        PosService::restoreOrderStock($order->fresh()); // idempotent
        $this->assertSame(3, (int) $item->fresh()->stock_qty, 'no double restore');
    }

    /** An oversell deducts fully (negative) so a later restore stays symmetric — no phantom stock. */
    public function test_oversell_deduct_and_restore_are_symmetric(): void
    {
        $r = $this->makeRestaurant($this->makeOwner());
        $item = $this->trackedItem($r, 3);

        Menu::deductStock($item->id, 5); // oversell by 2 → -2 (not clamped to 0)
        $this->assertSame(-2, (int) $item->fresh()->stock_qty);
        $this->assertTrue((bool) $item->fresh()->is_sold_out);

        Menu::restoreStock($item->id, 5); // symmetric → back to 3 (was 5 phantom before the fix)
        $this->assertSame(3, (int) $item->fresh()->stock_qty);
    }

    /** Parking a LIVE order releases its stock; resuming re-deducts exactly once. */
    public function test_hold_releases_stock_and_resume_rededucts_once(): void
    {
        $r = $this->makeRestaurant($this->makeOwner());
        $item = $this->trackedItem($r, 10);
        $order = $this->orderFor($r, $item, 3);
        Menu::deductStock($item->id, 3); // live deduction → 7
        $this->assertSame(7, (int) $item->fresh()->stock_qty);

        PosService::hold($order);
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'parking a live order released its stock');

        PosService::resume($order->fresh());
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'resume re-deducted once, not double to 4');
    }

    /** Voiding a parked (previously live) order neither leaks nor double-restores stock. */
    public function test_voiding_a_held_live_order_keeps_stock_correct(): void
    {
        $r = $this->makeRestaurant($this->makeOwner());
        $item = $this->trackedItem($r, 10);
        $order = $this->orderFor($r, $item, 3);
        Menu::deductStock($item->id, 3); // → 7

        PosService::hold($order);                 // released → 10
        PosService::void($order->fresh(), null, 'parked, abandoned');
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'no leak (was stuck at 7) and no double-restore');
    }

    public function test_restore_skips_held_order(): void
    {
        $r = $this->makeRestaurant($this->makeOwner());
        $item = $this->trackedItem($r, 3); // held order never deducted; stock still 3
        $order = $this->orderFor($r, $item, 3, ['held_at' => now()]);

        PosService::restoreOrderStock($order);

        $this->assertSame(3, (int) $item->fresh()->stock_qty, 'held order must not be restocked (would be 6)');
    }

    public function test_cancel_via_route_restocks(): void
    {
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $item = $this->trackedItem($r, 5);
        $order = $this->orderFor($r, $item, 2);
        Menu::deductStock($item->id, 2); // → 3
        $this->assertSame(3, (int) $item->fresh()->stock_qty);

        $this->actingAs($owner)
            ->post(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])
            ->assertStatus(200);

        $this->assertSame(5, (int) $item->fresh()->stock_qty, 'cancelling restored the deducted stock');
    }
}
