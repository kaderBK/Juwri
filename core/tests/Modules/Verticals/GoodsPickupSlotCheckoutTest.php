<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Tests\TestCase;

/**
 * rev/multi-vertical B3 — pickup-slot checkout, reusing the existing
 * SchedulingService (slotsFor/validate/lockSlot) rather than a forked picker.
 * The order carries the picked slot in `scheduled_at`; a slot already
 * locked/full is refused, exactly like the food scheduling flow.
 */
class GoodsPickupSlotCheckoutTest extends TestCase
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

    private function retailStoreWithScheduling(): Store
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);

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

    private function addToCart(Store $store, Menu $menu, int $qty = 1): void
    {
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => $qty]);
    }

    public function test_checkout_page_shows_a_slot_picker_for_pickup_slot(): void
    {
        $store = $this->retailStoreWithScheduling();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Candle', 'price' => 8, 'active' => 1]);
        $this->addToCart($store, $menu);

        // Only pickup_slot enabled so it's the checkout's default/only mode.
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        $res = $this->get(route('storefront.checkout.show', ['slug' => $store->slug]));

        $res->assertOk();
        $res->assertSeeHtml('name="scheduled_at"');
    }

    public function test_booking_a_pickup_slot_carries_the_slot_on_the_order(): void
    {
        $store = $this->retailStoreWithScheduling();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Candle', 'price' => 8, 'active' => 1]);
        $this->addToCart($store, $menu, 2);
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        $slot = Carbon::now('UTC')->addHours(2)->startOfHour();

        $res = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'pickup_slot',
            'customer_name' => 'Sam',
            'scheduled_at' => $slot->format('Y-m-d H:i'),
        ]);

        $res->assertRedirect();

        $order = Order::where('store_id', $store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('pickup_slot', $order->type);
        $this->assertNotNull($order->scheduled_at);
        $this->assertSame($slot->format('Y-m-d H:i'), $order->scheduled_at->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(16.0, (float) $order->fresh(['items'])->total, 0.001);
    }

    public function test_pickup_slot_without_a_chosen_time_is_rejected(): void
    {
        $store = $this->retailStoreWithScheduling();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Candle', 'price' => 8, 'active' => 1]);
        $this->addToCart($store, $menu);
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        $res = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'pickup_slot',
        ]);

        $res->assertSessionHasErrors('scheduled_at');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_a_full_slot_is_refused(): void
    {
        $store = $this->retailStoreWithScheduling();
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_capacity', 1);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Candle', 'price' => 8, 'active' => 1]);
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        $slot = Carbon::now('UTC')->addHours(2)->startOfHour();

        $this->addToCart($store, $menu);
        $first = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'pickup_slot',
            'scheduled_at' => $slot->format('Y-m-d H:i'),
        ]);
        $first->assertRedirect();

        $this->addToCart($store, $menu);
        $second = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'pickup_slot',
            'scheduled_at' => $slot->format('Y-m-d H:i'),
        ]);

        $second->assertSessionHasErrors('scheduled_at');
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }
}
