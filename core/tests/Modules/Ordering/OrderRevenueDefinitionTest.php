<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H10: the single revenue definition — scopeRevenueCounted() (SQL) and
 * countsAsRevenue() (collection) must agree, revenueSum() sums the snapshot column
 * over revenue-counting orders only, and unsnapshottedCount() sees legacy rows.
 */
class OrderRevenueDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function order(Store $r, Menu $item, string $status, bool $paid): Order
    {
        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);
        $order->forceFill(['status' => $status, 'is_paid' => $paid ? 1 : 0])->save();

        return $order->fresh();
    }

    public function test_scope_and_predicate_agree_on_every_status(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $i = 0;
        foreach (OrderStatus::values() as $status) {
            $this->order($r, $item, $status, $i % 2 === 0);
            $i++;
        }

        $fromScope = Order::query()->revenueCounted()->pluck('id')->sort()->values()->all();
        $fromPredicate = Order::all()->filter(fn ($o) => $o->countsAsRevenue())->pluck('id')->sort()->values()->all();

        $this->assertSame($fromScope, $fromPredicate);
    }

    public function test_revenue_sum_excludes_a_cancelled_unpaid_order(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $completed = $this->order($r, $item, OrderStatus::Completed->value, false);
        $this->order($r, $item, OrderStatus::Cancelled->value, false);

        $this->assertEqualsWithDelta(
            (float) $completed->grand_total,
            Order::revenueSum(Order::query()),
            0.001,
            'revenueSum must count only the completed order, not the cancelled unpaid one.'
        );
    }

    public function test_unsnapshotted_count_sees_a_legacy_row(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $order = $this->order($r, $item, OrderStatus::Completed->value, true);

        DB::table('orders')->where('id', $order->id)->update([
            'subtotal_minor' => null, 'tax_total_minor' => null, 'grand_total_minor' => null,
        ]);

        $this->assertSame(1, Order::unsnapshottedCount(Order::query()));
        $this->assertSame(0.0, Order::revenueSum(Order::query()));
    }
}
