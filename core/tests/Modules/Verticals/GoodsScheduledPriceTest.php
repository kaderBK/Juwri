<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\PriceSchedule;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P1-12 (pharmacy promotions journey) — two goods-checkout bugs:
 *  1. the storefront showed the scheduled (sale / happy-hour) price but the goods cart and checkout charged the LIST
 *     price (the customer paid more than shown; no schedule snapshot on the line);
 *  2. a goods order never earned loyalty points (the goods checkout could redeem points but not earn them).
 */
class GoodsScheduledPriceTest extends TestCase
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
        config(['settings.admin_allow_menu_schedules' => true, 'settings.admin_allow_loyalty' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();
    }

    private function store(): Store
    {
        $owner = User::factory()->create();
        EntitlementWriter::grant($owner, 'loyalty', 'admin', null, '1', null, 'test');
        FeatureAccess::flush();

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
    }

    private function sale(Store $store): void
    {
        PriceSchedule::create(['store_id' => $store->id, 'name' => 'Sale', 'label' => 'Sale', 'scope' => 'store',
            'adjustment_type' => 'percent', 'adjustment_value' => 20, 'active' => true]);
        MenuScheduleService::flush();
    }

    public function test_the_goods_cart_and_checkout_charge_the_scheduled_price_the_storefront_shows(): void
    {
        $store = $this->store();
        $this->sale($store);
        $mug = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 10, 'active' => 1]);

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $mug->id]), ['quantity' => 2]);
        $token = (string) Cart::query()->where('store_id', $store->id)->value('guest_token');
        $line = app(GoodsCartService::class)->lines($store, $token)->sole();
        $this->assertEqualsWithDelta(8.0, (float) $line['unit_price'], 0.001, 'cart line at the sale price'); // before: 10.0
        $this->assertEqualsWithDelta(16.0, (float) $line['line_total'], 0.001);

        $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship', 'address' => '1 St', 'customer_name' => 'Jo', 'phone_number' => '5551234',
        ])->assertRedirect();

        $order = Order::where('store_id', $store->id)->sole();
        $item = $order->items()->sole();
        $this->assertEqualsWithDelta(8.0, (float) $item->unit_price, 0.001);      // before: null (list price)
        $this->assertNotNull($item->price_schedule_id);
        $this->assertEqualsWithDelta(16.0, (float) $order->fresh(['items'])->total, 0.001); // before: 20.0
    }

    public function test_a_goods_order_earns_loyalty_points_for_the_signed_in_customer(): void
    {
        $store = $this->store();
        $mug = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 10, 'active' => 1]);
        LoyaltyProgram::create(['store_id' => $store->id, 'type' => 'points', 'earn_rate' => 100, 'reward_threshold' => 50,
            'reward_description' => 'R', 'reward_discount' => 5, 'active' => true]);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-')]);

        $this->actingAs($customer, 'customer')->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $mug->id]), ['quantity' => 3]);
        $this->actingAs($customer, 'customer')->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship', 'address' => '1 St', 'customer_name' => 'Ada', 'phone_number' => '5551234',
        ])->assertRedirect();

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame((int) $customer->id, (int) $order->customer_id);
        $this->assertSame(30, (int) LoyaltyAccount::forCustomer($customer->id, $store->id)->fresh()->balance); // before: 0
    }
}
