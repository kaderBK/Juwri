<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The delivery pin a diner sets (saved-address chip, cart map, or "use my
 * location") must land on orders.delivery_lat/delivery_lng, which delivery
 * dispatch (DispatchService) reads. Before this, submitOrder validated only the
 * address string and every storefront delivery order reached the driver with
 * null coordinates.
 *
 * Themes name the hidden inputs differently, so the write accepts all three
 * conventions (address_lat/lng, latitude/longitude, delivery_lat/lng) via
 * storefront_delivery_coords(); out-of-range or non-delivery orders store null.
 */
class DeliveryCoordsOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Order placement fans out to mail/notifications/queue; fake them so the
        // test exercises the order write, not the comms (the confirmation email
        // template renders empty in the test env and 500s otherwise).
        Mail::fake();
        Notification::fake();
        Queue::fake();
        // send-order returns JSON (no view render); shared globals kept canonical
        // so this file stays out of ViewComposerContractTest's offender scan.
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function makeItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item',
            'price' => 10.00, 'active' => 1,
        ]);
    }

    private function placeDelivery(Store $store, Menu $item, array $extra = []): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Delivery Guest',
            'ordering-type' => 'delivery',
            'phone-number' => '555-1000',
            'address' => '12 Test Street, Riyadh',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    private function latestOrder(Store $store): Order
    {
        return Order::where('store_id', $store->id)->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- positive

    public function test_address_lat_lng_persist_to_the_order(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        $this->placeDelivery($store, $item, [
            'address_lat' => '24.7136',
            'address_lng' => '46.6753',
        ])->assertOk();

        $order = $this->latestOrder($store);
        $this->assertEqualsWithDelta(24.7136, (float) $order->delivery_lat, 0.0001);
        $this->assertEqualsWithDelta(46.6753, (float) $order->delivery_lng, 0.0001);
    }

    public function test_velvet_style_latitude_longitude_names_also_persist(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        $this->placeDelivery($store, $item, [
            'latitude' => '21.4225',
            'longitude' => '39.8262',
        ])->assertOk();

        $order = $this->latestOrder($store);
        $this->assertEqualsWithDelta(21.4225, (float) $order->delivery_lat, 0.0001);
        $this->assertEqualsWithDelta(39.8262, (float) $order->delivery_lng, 0.0001);
    }

    public function test_canonical_delivery_lat_lng_names_persist(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        $this->placeDelivery($store, $item, [
            'delivery_lat' => '26.4207',
            'delivery_lng' => '50.0888',
        ])->assertOk();

        $order = $this->latestOrder($store);
        $this->assertEqualsWithDelta(26.4207, (float) $order->delivery_lat, 0.0001);
        $this->assertEqualsWithDelta(50.0888, (float) $order->delivery_lng, 0.0001);
    }

    // ---------------------------------------------------------------- negative

    public function test_out_of_range_coords_are_dropped_not_saved(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        // lat 999 is impossible; the order still places, just without a pin.
        $this->placeDelivery($store, $item, [
            'address_lat' => '999',
            'address_lng' => '46.6753',
        ])->assertOk();

        $order = $this->latestOrder($store);
        $this->assertNull($order->delivery_lat);
        $this->assertNull($order->delivery_lng);
    }

    public function test_delivery_maps_url_built_from_order_coords(): void
    {
        $withPin = (object) ['delivery_lat' => 24.7136, 'delivery_lng' => 46.6753];
        $url = order_delivery_maps_url($withPin);
        $this->assertNotNull($url);
        $this->assertStringContainsString('google.com/maps', $url);
        $this->assertStringContainsString('24.7136', $url);
        $this->assertStringContainsString('46.6753', $url);

        $this->assertNull(order_delivery_maps_url((object) ['delivery_lat' => null, 'delivery_lng' => null]));
        $this->assertNull(order_delivery_maps_url((object) ['delivery_lat' => '', 'delivery_lng' => '']));
        $this->assertNull(order_delivery_maps_url(null));
    }

    public function test_non_delivery_order_never_stores_coords(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);
        StoreTable::firstOrCreate(
            ['store_id' => $store->id, 'table_no' => 7],
            ['key' => Str::random(10)]
        );

        $this->post(route('store.submitOrder', $store), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Dine In',
            'ordering-type' => 'on-table',
            'table' => '7',
            'pay_via' => 'cash',
            'total' => '10.00',
            // A stray pin on a dine-in order must be ignored.
            'address_lat' => '24.7136',
            'address_lng' => '46.6753',
        ])->assertOk();

        $order = $this->latestOrder($store);
        $this->assertNull($order->delivery_lat);
        $this->assertNull($order->delivery_lng);
    }
}
