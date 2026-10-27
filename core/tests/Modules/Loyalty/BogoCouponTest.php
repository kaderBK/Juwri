<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS E5 — buy-X-get-Y (BOGO) coupon type. Computation lives inside CouponService
 * and is applied at the OrderPricing settlement seam (no StoreController edits).
 */
class BogoCouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
    }

    private function store(User $owner): Store
    {
        $t = 'S'.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function category(Store $store): int
    {
        return (int) MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat'.Str::random(4)])->id;
    }

    private function menu(Store $store, ?int $categoryId = null): Menu
    {
        $categoryId ??= $this->category($store);

        return Menu::create(['store_id' => $store->id, 'category_id' => $categoryId, 'name' => 'M'.Str::random(4), 'price' => 10]);
    }

    /** @param array<int,array{item_id:int,qty:int,price:float}> $lines */
    private function orderWithItems(Store $store, User $owner, array $lines): Order
    {
        $order = Order::factory()->create(['store_id' => $store->id, 'is_paid' => 0]);
        foreach ($lines as $l) {
            OrderItem::create(['order_id' => $order->id, 'item_id' => $l['item_id'], 'quantity' => $l['qty'], 'unit_price' => $l['price']]);
        }

        return $order->fresh();
    }

    private function bogo(User $owner, Store $store, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'BOGO'.Str::upper(Str::random(4)), 'coupon_type' => 'bogo', 'user_id' => $owner->id,
            'percentage' => 0, 'limit' => 9999, 'buy_scope' => 'item', 'buy_qty' => 2, 'get_qty' => 1,
            'get_discount_percent' => 100,
        ], $overrides));
    }

    public function test_bogo_qualifies_and_discounts_get_item(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $pizza = $this->menu($store);
        $soda = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $pizza->id, 'get_id' => $soda->id]);

        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $pizza->id, 'qty' => 2, 'price' => 12],
            ['item_id' => $soda->id, 'qty' => 1, 'price' => 5],
        ]);

        $this->assertSame(5.0, (new CouponService)->bogoDiscount($coupon, $order));
    }

    public function test_bogo_not_qualified_when_buy_qty_unmet(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $pizza = $this->menu($store);
        $soda = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $pizza->id, 'get_id' => $soda->id]);

        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $pizza->id, 'qty' => 1, 'price' => 12],
            ['item_id' => $soda->id, 'qty' => 1, 'price' => 5],
        ]);

        $this->assertSame(0.0, (new CouponService)->bogoDiscount($coupon, $order));
    }

    public function test_bogo_repeats_per_multiple_capped_by_get_qty(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $pizza = $this->menu($store);
        $soda = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $pizza->id, 'get_id' => $soda->id]);

        // 4 pizzas => 2 repeats => up to 2 sodas free; 3 sodas in cart caps at 2.
        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $pizza->id, 'qty' => 4, 'price' => 12],
            ['item_id' => $soda->id, 'qty' => 3, 'price' => 5],
        ]);

        $this->assertSame(10.0, (new CouponService)->bogoDiscount($coupon, $order));
    }

    public function test_bogo_category_scope(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $catBuy = $this->category($store);
        $catOther = $this->category($store);
        $inCatA = $this->menu($store, $catBuy);
        $inCatB = $this->menu($store, $catBuy);
        $soda = $this->menu($store, $catOther);
        $coupon = $this->bogo($owner, $store, ['buy_scope' => 'category', 'buy_id' => $catBuy, 'get_id' => $soda->id]);

        // 2 items in category 7 => 1 repeat => 1 soda free.
        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $inCatA->id, 'qty' => 1, 'price' => 12],
            ['item_id' => $inCatB->id, 'qty' => 1, 'price' => 8],
            ['item_id' => $soda->id, 'qty' => 1, 'price' => 6],
        ]);

        $this->assertSame(6.0, (new CouponService)->bogoDiscount($coupon, $order));
    }

    public function test_bogo_same_item_buy_one_get_one_free(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $x = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $x->id, 'get_id' => $x->id, 'buy_qty' => 1, 'get_qty' => 1]);

        // 2 of X => 1 free; 4 of X => 2 free.
        $o2 = $this->orderWithItems($store, $owner, [['item_id' => $x->id, 'qty' => 2, 'price' => 8]]);
        $this->assertSame(8.0, (new CouponService)->bogoDiscount($coupon, $o2));

        $o4 = $this->orderWithItems($store, $owner, [['item_id' => $x->id, 'qty' => 4, 'price' => 8]]);
        $this->assertSame(16.0, (new CouponService)->bogoDiscount($coupon, $o4));
    }

    /**
     * HPMS §8-P1: when the GET item is itself inside the BUY category, its units are
     * also counted as buy units — they must share ONE cycle, not inflate repeats.
     */
    public function test_bogo_category_scope_get_item_in_buy_category_no_double(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $catBuy = $this->category($store);
        $getItem = $this->menu($store, $catBuy); // the get item lives in the buy category
        $coupon = $this->bogo($owner, $store, [
            'buy_scope' => 'category', 'buy_id' => $catBuy, 'get_id' => $getItem->id,
            'buy_qty' => 1, 'get_qty' => 1,
        ]);

        // 2 units of the get item (both in the buy category): buy-1-get-1 → exactly ONE
        // free (10), not both (20).
        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $getItem->id, 'qty' => 2, 'price' => 10],
        ]);

        $this->assertSame(10.0, (new CouponService)->bogoDiscount($coupon, $order));
    }

    public function test_bogo_partial_percent(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $pizza = $this->menu($store);
        $soda = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $pizza->id, 'get_id' => $soda->id, 'get_discount_percent' => 50]);

        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $pizza->id, 'qty' => 2, 'price' => 12],
            ['item_id' => $soda->id, 'qty' => 1, 'price' => 8],
        ]);
        $this->assertSame(4.0, (new CouponService)->bogoDiscount($coupon, $order)); // 8 * 50%
    }

    public function test_bogo_applies_and_stacks_at_settlement(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $pizza = $this->menu($store);
        $soda = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $pizza->id, 'get_id' => $soda->id]);

        $order = $this->orderWithItems($store, $owner, [
            ['item_id' => $pizza->id, 'qty' => 2, 'price' => 12],
            ['item_id' => $soda->id, 'qty' => 1, 'price' => 5],
        ]);
        // Pre-existing manual discount — the coupon stacks on top (existing semantics).
        $order->discount_amount = 3;
        $order->save();

        $res = OrderPricing::applyCouponAndCredit($order, ['coupon_code' => $coupon->code, 'owner_id' => $owner->id]);

        $this->assertSame(5.0, (float) $res['discount']);
        $this->assertSame($coupon->id, $res['coupon_id']);
        $this->assertSame(8.0, (float) $order->fresh()->discount_amount, 'BOGO stacks on the manual discount');
    }

    public function test_admin_form_persists_bogo_coupon(): void
    {
        $this->withoutMiddleware();
        $owner = $this->owner();
        $store = $this->store($owner);
        // P6-59b: buy/get ids must be the owner's own items (another tenant's id is refused).
        $buy = $this->menu($store);
        $get = $this->menu($store);

        $this->actingAs($owner)->post(route('stores.coupons.store'), [
            'code' => 'bogoadmin', 'coupon_type' => 'bogo', 'buy_scope' => 'item',
            'buy_id' => $buy->id, 'buy_qty' => 2, 'get_id' => $get->id, 'get_qty' => 1, 'get_discount_percent' => 100,
        ])->assertRedirect();

        $this->assertDatabaseHas('coupons', [
            'code' => 'BOGOADMIN', 'coupon_type' => 'bogo', 'buy_scope' => 'item',
            'buy_id' => $buy->id, 'buy_qty' => 2, 'get_id' => $get->id, 'get_qty' => 1, 'user_id' => $owner->id,
        ]);
        $this->assertSame(100.0, (float) Coupon::where('code', 'BOGOADMIN')->value('get_discount_percent'));
    }

    public function test_admin_form_rejects_incomplete_bogo(): void
    {
        $this->withoutMiddleware();
        $owner = $this->owner();

        $this->actingAs($owner)->from('/')->post(route('stores.coupons.store'), [
            'code' => 'BADBOGO', 'coupon_type' => 'bogo', // missing buy/get params
        ])->assertSessionHasErrors(['buy_id', 'get_id', 'get_discount_percent']);

        $this->assertDatabaseMissing('coupons', ['code' => 'BADBOGO']);
    }

    public function test_pre_flight_without_items_returns_zero_but_ok(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $pizza = $this->menu($store);
        $soda = $this->menu($store);
        $coupon = $this->bogo($owner, $store, ['buy_id' => $pizza->id, 'get_id' => $soda->id]);

        // The subtotal-only pre-flight (no order) validates but shows 0 discount.
        $res = (new CouponService)->resolve($coupon->code, ['surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 30]);
        $this->assertTrue($res['ok']);
        $this->assertSame(0.0, (float) $res['discount']);
    }
}
