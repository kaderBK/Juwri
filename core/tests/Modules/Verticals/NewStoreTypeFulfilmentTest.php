<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Tests\TestCase;

/**
 * P3-00 part 2 — the order flow of the three new store types (FULFILMENT: coordinator review).
 *
 *  - services: `appointment` needs a time slot; `at_home` needs a time slot AND an address; nothing else is offered.
 *  - grocery: `delivery` needs an address; `pickup_slot` needs a slot; `ship` / `in_store` are refused.
 *  - cafe: a food store — the legacy food modes work through OrderApiService, `ship` does not become an order type.
 */
class NewStoreTypeFulfilmentTest extends TestCase
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

    private function store(string $type): Store
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => $type]);
        foreach (['allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30, 'scheduling_lead_minutes' => 30, 'scheduling_prep_minutes' => 20, 'timezone' => 'UTC'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function slot(): string
    {
        return Carbon::now('UTC')->addHours(2)->startOfHour()->format('Y-m-d H:i');
    }

    private function book(Store $store, array $body)
    {
        $service = Menu::create(['store_id' => $store->id, 'name' => 'Haircut', 'price' => 120, 'pricing_unit' => 'each', 'active' => 1]);
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $service->id]), ['quantity' => 1]);

        return $this->post(route('laundry.book.place', ['slug' => $store->slug]), $body + ['customer_name' => 'Sam', 'phone_number' => '555']);
    }

    public function test_at_home_is_a_defined_mode_that_needs_an_address_and_a_slot(): void
    {
        $catalog = app(FulfillmentRegistry::class);

        $this->assertTrue($catalog->isValid('at_home'));
        $this->assertTrue($catalog->needsAddress('at_home'));
        $this->assertTrue($catalog->needsSlot('at_home'));
        $this->assertSame(['appointment', 'at_home'], $catalog->modesFor('services'));
        $this->assertSame(['delivery', 'pickup_slot'], $catalog->modesFor('grocery'));
        $this->assertSame(['on-table', 'takeaway', 'delivery'], $catalog->modesFor('cafe'));
    }

    public function test_services_at_home_booking_needs_the_address_and_the_slot_and_carries_both(): void
    {
        $store = $this->store('services');
        $slot = $this->slot();

        $this->book($store, ['type' => 'at_home', 'scheduled_at' => $slot])->assertSessionHasErrors('address');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());

        $this->book($store, ['type' => 'at_home', 'address' => '12 Palm St'])->assertSessionHasErrors('scheduled_at');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());

        $this->book($store, ['type' => 'at_home', 'address' => '12 Palm St', 'scheduled_at' => $slot])->assertRedirect();
        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('at_home', $order->type);
        $this->assertSame('12 Palm St', $order->address);
        $this->assertSame($slot, $order->scheduled_at->format('Y-m-d H:i'));
    }

    public function test_services_appointment_needs_only_a_slot_and_delivery_is_not_offered(): void
    {
        $store = $this->store('services');

        $this->book($store, ['type' => 'appointment'])->assertSessionHasErrors('scheduled_at');
        $this->book($store, ['type' => 'appointment', 'scheduled_at' => $this->slot()])->assertRedirect();
        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('appointment', $order->type);
        $this->assertNull($order->address);

        $this->book($store, ['type' => 'delivery', 'address' => '1 St'])->assertSessionHasErrors('type');
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_services_at_home_can_be_switched_off_by_the_owner(): void
    {
        $store = $this->store('services');
        app(FulfillmentSettingsService::class)->update($store, ['appointment']);

        $this->book($store, ['type' => 'at_home', 'address' => '12 Palm St', 'scheduled_at' => $this->slot()])->assertSessionHasErrors('type');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    private function grocery(string $mode, array $body)
    {
        $store = $this->store('grocery');
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Bananas', 'price' => 6.5, 'pricing_unit' => 'kg', 'active' => 1]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 2]);

        return [$store, $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), ['type' => $mode, 'customer_name' => 'Sam', 'phone_number' => '555'] + $body)];
    }

    public function test_grocery_delivery_needs_an_address_and_pickup_needs_a_slot(): void
    {
        [$store, $res] = $this->grocery('delivery', []);
        $res->assertSessionHasErrors('address');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());

        [$store, $res] = $this->grocery('delivery', ['address' => '3 Olaya Rd']);
        $res->assertRedirect();
        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('delivery', $order->type);
        $this->assertSame('3 Olaya Rd', $order->address);

        [$store, $res] = $this->grocery('pickup_slot', []);
        $res->assertSessionHasErrors('scheduled_at');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());

        [$store, $res] = $this->grocery('pickup_slot', ['scheduled_at' => $this->slot()]);
        $res->assertRedirect();
        $this->assertSame('pickup_slot', Order::where('store_id', $store->id)->firstOrFail()->type);
    }

    public function test_grocery_refuses_ship_and_in_store(): void
    {
        foreach (['ship', 'in_store', 'at_home', 'appointment'] as $mode) {
            [$store, $res] = $this->grocery($mode, ['address' => '1 St', 'scheduled_at' => $this->slot()]);
            $res->assertSessionHasErrors('type');
            $this->assertSame(0, Order::where('store_id', $store->id)->count(), "grocery accepted $mode");
        }
    }

    public function test_cafe_takes_the_food_modes_and_ship_never_becomes_an_order_type(): void
    {
        $store = $this->store('cafe');
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Flat white', 'price' => 16, 'active' => 1]);

        foreach (['on-table', 'takeaway', 'delivery'] as $mode) {
            $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
                'type' => $mode, 'items' => [['item_id' => $menu->id, 'quantity' => 1]],
            ]);
            $this->assertSame($mode, $order->type);
        }

        // A mode of another vertical is coerced to the food default, never stored as-is.
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'at_home', 'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);
        $this->assertSame('takeaway', $order->type);
    }
}
