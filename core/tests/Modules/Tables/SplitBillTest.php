<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Core\Models\Language;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Core\Models\User;
use Juwri\Modules\Tables\Services\SplitBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class SplitBillTest extends TestCase
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

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Build an order whose total is a known value via one item. */
    private function makeOrder(Store $r, float $price, int $qty = 1): Order
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => $price, 'active' => '1']);
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new', 'created_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'variation' => 0]);
        return $order->fresh();
    }

    /** Even split with remainder lands exactly on the total */
    public function test_even_split_remainder_sums_to_total(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = $this->makeOrder($r, 10.00); // total 10.00 (no tax configured)

        $check = SplitBillService::createEvenSplit($order, 3);

        $shares = $check->payments->pluck('amount')->map(fn ($a) => (float) $a)->all();
        $this->assertCount(3, $shares);
        $this->assertEquals(10.00, round(array_sum($shares), 2));
        // 3.33, 3.33, 3.34
        $this->assertEqualsWithDelta(3.34, max($shares), 0.001);
    }

    /** Paying all shares closes the check and marks the order paid */
    public function test_paying_all_shares_settles_order(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = $this->makeOrder($r, 20.00);

        $this->actingAs($owner)->post(route('orders.split.store', $order->id), ['parts' => 2])->assertRedirect();
        $check = TableCheck::where('order_id', $order->id)->first();
        $this->assertEquals(2, $check->payments()->count());

        $shares = $check->payments;
        $this->actingAs($owner)->post(route('orders.split.pay', $shares[0]->id), ['method' => 'cash'])->assertRedirect();
        $this->assertEquals('partial', $check->fresh()->status);
        $this->assertEquals(0, (int) $order->fresh()->is_paid);

        $this->actingAs($owner)->post(route('orders.split.pay', $shares[1]->id), ['method' => 'card'])->assertRedirect();
        $this->assertEquals('paid', $check->fresh()->status);
        $this->assertEquals(1, (int) $order->fresh()->is_paid);
    }

    /** Re-splitting before any payment replaces the shares */
    public function test_resplit_replaces_unpaid_shares(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = $this->makeOrder($r, 12.00);

        SplitBillService::createEvenSplit($order, 2);
        SplitBillService::createEvenSplit($order, 4);

        $this->assertEquals(1, TableCheck::where('order_id', $order->id)->count());
        $this->assertEquals(4, CheckPayment::whereHas('tableCheck', fn ($q) => $q->where('order_id', $order->id))->count());
    }

    /**
     * S1: re-splitting after a share is paid must be refused. The old code kept the
     * paid check AND created a second full-total check, so paying the new shares
     * collected the order total a second time ($40 collectible for a $20 order).
     */
    public function test_cannot_resplit_after_a_share_is_paid(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = $this->makeOrder($r, 20.00);

        $check = SplitBillService::createEvenSplit($order, 2); // 10 + 10
        SplitBillService::markSharePaid($check->payments->first()); // pay one 10

        try {
            SplitBillService::createEvenSplit($order->fresh(), 2);
            $this->fail('re-splitting a bill that already has payments must throw');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // expected
        }

        // Exactly one check, and the total collectible never exceeds the order total.
        $this->assertEquals(1, TableCheck::where('order_id', $order->id)->count());
        $collectible = CheckPayment::whereHas('tableCheck', fn ($q) => $q->where('order_id', $order->id))->sum(DB::raw(CheckPayment::minorMoneySql('amount')));
        $this->assertEquals(20.00, round((float) $collectible, 2), 'collectible stays at the order total, not doubled');
    }

    /** Tenant isolation: cannot split or pay another owner's order */
    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = $this->makeOrder($r, 10.00);
        $check = SplitBillService::createEvenSplit($order, 2);

        $intruder = $this->makeUser();
        $this->actingAs($intruder)->post(route('orders.split.store', $order->id), ['parts' => 5])->assertNotFound();
        $this->actingAs($intruder)->post(route('orders.split.pay', $check->payments->first()->id))->assertNotFound();
    }

    /** P6-180: replaying a share payment keeps the first payment and settles the order once. */
    public function test_share_pay_replay_keeps_first_payment(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = $this->makeOrder($r, 20.00);
        $check = SplitBillService::createEvenSplit($order, 2);
        [$s1, $s2] = [$check->payments[0], $check->payments[1]];

        $this->actingAs($owner)->post(route('orders.split.pay', $s1->id), ['method' => 'cash'])->assertRedirect();
        $paidAt = $s1->fresh()->paid_at;
        // Stale model (as a concurrent request would hold) replays with another method.
        SplitBillService::markSharePaid($s1, null, 'card');
        $this->assertSame('cash', $s1->fresh()->method);
        $this->assertEquals($paidAt, $s1->fresh()->paid_at);
        $this->assertEquals('partial', $check->fresh()->status);

        $this->actingAs($owner)->post(route('orders.split.pay', $s2->id), ['method' => 'card'])->assertRedirect();
        $this->actingAs($owner)->post(route('orders.split.pay', $s2->id), ['method' => 'cash'])->assertRedirect();
        $this->assertSame('card', $s2->fresh()->method);
        $this->assertEquals(1, (int) $order->fresh()->is_paid);
    }
}
