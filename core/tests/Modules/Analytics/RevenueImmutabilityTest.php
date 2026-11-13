<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SalesAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A closed period's revenue must be immutable: it reports the FROZEN grand_total
 * snapshot, not the live Order->total accessor which re-derives from current menu
 * prices — so editing a price today does not silently rewrite last month's revenue.
 * A row whose snapshot is still NULL falls back to the accessor (never under-counts).
 */
class RevenueImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Menu} */
    private function storeWithItem(float $price): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);

        return [$store, $menu];
    }

    private function paidOrder(Store $store, Menu $menu, int $qty = 1): Order
    {
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1,
            'track_token' => Str::random(16), 'created_at' => '2026-08-10 12:00:00',
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);

        return $order->fresh();
    }

    public function test_closed_period_revenue_does_not_drift_when_a_menu_price_changes(): void
    {
        [$store, $menu] = $this->storeWithItem(10.0);
        $order = $this->paidOrder($store, $menu);
        $order->load('items')->snapshotTotals(); // freeze grand_total = 10 (as the order-write hooks do)

        $before = SalesAnalyticsService::summary($store->id, '2026-08-10', '2026-08-10');
        $this->assertEqualsWithDelta(10.0, $before['revenue']['paid'], 0.001, 'fixture: sold at 10');

        // Owner raises the price AFTER the sale.
        $menu->update(['price' => 20.0]);

        $after = SalesAnalyticsService::summary($store->id, '2026-08-10', '2026-08-10');
        $this->assertEqualsWithDelta(10.0, $after['revenue']['paid'], 0.001, 'the closed sale still reports 10, not the re-priced 20');
        $this->assertSame($before['revenue']['paid'], $after['revenue']['paid'], 'historical revenue is immutable');
    }

    public function test_an_unsnapshotted_order_falls_back_to_the_live_total(): void
    {
        [$store, $menu] = $this->storeWithItem(15.0);
        $this->paidOrder($store, $menu); // never snapshotted → grand_total is NULL

        $summary = SalesAnalyticsService::summary($store->id, '2026-08-10', '2026-08-10');
        // NULL snapshot must fall back to the accessor (15), never contribute 0.
        $this->assertEqualsWithDelta(15.0, $summary['revenue']['paid'], 0.001);
    }
}
