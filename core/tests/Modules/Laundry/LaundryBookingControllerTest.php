<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Laundry;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane D — D2: booking order type + pickup/delivery
 * (DECISIONS A9).
 *
 * A laundry storefront booking mixes an each-priced service (garments) with a
 * per-kg service (weight) in one order, with either a pickup slot or a
 * delivery address, and totals correctly — reusing GoodsCartService /
 * GoodsOrderService (Lane B) end to end via Juwri\Modules\Laundry\Services\Laundry\ServiceBookingService.
 */
class LaundryBookingControllerTest extends TestCase
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

    private function laundryStore(): Store
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'laundry']);

        foreach ([
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30,
            'scheduling_prep_minutes' => 20,
            'timezone' => 'UTC',
        ] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function shirtIron(Store $store): Menu
    {
        return Menu::create([
            'store_id' => $store->id, 'name' => 'Shirt — iron', 'price' => 3.5,
            'pricing_unit' => 'each', 'active' => 1,
        ]);
    }

    private function washAndFold(Store $store): Menu
    {
        return Menu::create([
            'store_id' => $store->id, 'name' => 'Wash & fold', 'price' => 5,
            'pricing_unit' => 'kg', 'active' => 1,
        ]);
    }

    public function test_booking_page_is_reachable_for_a_laundry_store(): void
    {
        $store = $this->laundryStore();
        $this->shirtIron($store);

        $res = $this->get(route('laundry.book.show', ['slug' => $store->slug]));

        $res->assertOk();
        $res->assertSee('Shirt — iron');
    }

    public function test_booking_page_404s_for_a_restaurant_store(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'restaurant']);

        $res = $this->get(route('laundry.book.show', ['slug' => $store->slug]));

        $res->assertNotFound();
    }

    public function test_a_per_kg_line_shows_the_weight_aware_total(): void
    {
        $store = $this->laundryStore();
        $wash = $this->washAndFold($store);

        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), [
            'quantity' => 2.5,
        ]);

        $res = $this->get(route('laundry.book.show', ['slug' => $store->slug]));
        $res->assertOk();
        // 2.5kg × 5.00/kg = 12.50, NOT 5.00 (which a naive qty=1 × price read would show).
        $res->assertSee('12.50');
    }

    public function test_booking_a_mixed_order_with_pickup_slot_totals_correctly(): void
    {
        $store = $this->laundryStore();
        $shirt = $this->shirtIron($store);
        $wash = $this->washAndFold($store);
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        // 3 shirts @ 3.50 = 10.50
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 3]);
        // 2kg wash & fold @ 5.00/kg = 10.00
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), ['quantity' => 2]);

        $slot = Carbon::now('UTC')->addHours(2)->startOfHour();

        $res = $this->post(route('laundry.book.place', ['slug' => $store->slug]), [
            'type' => 'pickup_slot',
            'customer_name' => 'Sam',
            'address' => '221B Baker Street',
            'scheduled_at' => $slot->format('Y-m-d H:i'),
        ]);

        $res->assertRedirect();

        $order = Order::where('store_id', $store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('pickup_slot', $order->type);
        $this->assertSame(2, $order->items()->count());

        $kgItem = OrderItem::where('order_id', $order->id)->where('item_id', $wash->id)->first();
        $this->assertNotNull($kgItem);
        $this->assertEqualsWithDelta(2.0, (float) $kgItem->weight_kg, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $kgItem->unit_price, 0.001);

        // 10.50 + 10.00 = 20.50
        $this->assertEqualsWithDelta(20.5, (float) $order->fresh(['items'])->total, 0.001);
    }

    public function test_a_percentage_coupon_discounts_the_weight_corrected_subtotal(): void
    {
        // Regression: laundry checkout used to drop coupon/gift/loyalty entirely
        // (the controller never forwarded them). And because a per-kg line rides
        // the cart as qty 1 × price_per_kg, the discount must be settled AFTER the
        // weight correction or a percentage coupon would discount the wrong base.
        $store = $this->laundryStore();
        $wash = $this->washAndFold($store);
        app(FulfillmentSettingsService::class)->update($store, ['delivery']);

        Coupon::create([
            'code' => 'WASH20', 'coupon_type' => 'percentage', 'percentage' => 20,
            'limit' => 100, 'used' => 0, 'user_id' => $store->user_id,
        ]);

        // 2kg × 5.00/kg = 10.00 subtotal (NOT the pre-weight 5.00).
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), ['quantity' => 2]);

        $res = $this->post(route('laundry.book.place', ['slug' => $store->slug]), [
            'type' => 'delivery',
            'customer_name' => 'Sam',
            'address' => '221B Baker Street',
            'coupon_code' => 'WASH20',
        ]);

        $res->assertRedirect();

        $order = Order::where('store_id', $store->id)->first()->fresh(['items']);
        // 20% of the corrected 10.00 = 2.00 off → 8.00 (a pre-weight base of 5.00
        // would wrongly give 1.00 off → 9.00).
        $this->assertEqualsWithDelta(2.0, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $order->total, 0.001);
    }

    public function test_booking_with_delivery_carries_the_address(): void
    {
        $store = $this->laundryStore();
        $wash = $this->washAndFold($store);
        app(FulfillmentSettingsService::class)->update($store, ['delivery']);

        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), ['quantity' => 1.2]);

        $res = $this->post(route('laundry.book.place', ['slug' => $store->slug]), [
            'type' => 'delivery',
            'customer_name' => 'Sam',
            'address' => '221B Baker Street',
        ]);

        $res->assertRedirect();

        $order = Order::where('store_id', $store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('delivery', $order->type);
        $this->assertSame('221B Baker Street', $order->address);
        // 1.2kg × 5.00/kg = 6.00
        $this->assertEqualsWithDelta(6.0, (float) $order->fresh(['items'])->total, 0.001);
    }
}
