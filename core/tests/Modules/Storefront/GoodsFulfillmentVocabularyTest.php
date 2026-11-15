<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The goods vocabulary.
 *
 * A clothing store is not a restaurant that forgot to change its words. When
 * the Liquid storefront rendered the platform's food checkout for a goods
 * store, the customer was offered "Dine-in / Takeaway / Delivery" and the
 * order was RECORDED as `type = takeaway` — so the goods order board, the
 * goods track page ("Ready to ship" vs "Ready for pickup") and every
 * downstream report read a parcel as a plate of food.
 */
class GoodsFulfillmentVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Menu $hoodie;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.currency_code' => 'USD']);
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();

        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1, 'position' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            LaravelLocalizationRedirectFilter::class,
            ThrottleRequests::class,
        ]);

        $this->store = $this->makeStore('clothing');
    }

    private function makeStore(?string $storeType): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'goods-'.Str::lower(Str::random(8)), 'color' => '#7c5cff',
            'title' => 'Goods Store', 'sub_title' => 'Shop', 'description' => 'Clothes',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ], $storeType === null ? [] : ['store_type' => $storeType]));
        StoreSettings::set($store->id, 'restaurant_template', 'mp:blooms@1.2.8');
        StoreSettings::set($store->id, 'restaurant_takeaway_order', 1);
        StoreSettings::set($store->id, 'restaurant_delivery_order', 1);
        StoreSettings::set($store->id, 'allow_order', 1);
        StoreSettings::set($store->id, 'currency_code', 'USD');
        StoreSettings::set($store->id, 'default_language', 'en');

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Hoodies', 'active' => 1]);
        $this->hoodie = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Merino Hoodie',
            'price' => 42.00, 'active' => 1,
        ]);

        return $store;
    }

    private function config(Store $store, array $flags = []): array
    {
        return (new CommerceRuntime)->config([
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [],
            'flags' => $flags,
            'customer' => null,
            'table' => null,
        ]);
    }

    private function checkout(array $overrides = [])
    {
        return $this->postJson(route('store.submitOrder', $this->store), array_merge([
            'items' => json_encode([['id' => $this->hoodie->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Jane Customer', 'ordering-type' => 'ship', 'phone-number' => '555-0100',
            'address' => '12 High Street', 'pay_via' => 'cash', 'tip_amount' => '0',
        ], $overrides));
    }

    private function lastOrder(): ?Order
    {
        return Order::where('store_id', $this->store->id)->latest('id')->first();
    }

    public function test_a_goods_store_offers_its_own_fulfillment_modes(): void
    {
        $cfg = $this->config($this->store);
        $modes = $cfg['fulfillmentModes'];

        $this->assertSame(['ship', 'pickup_slot', 'in_store'], array_column($modes, 'value'),
            'a goods store offers its vertical\'s modes, not the restaurant trio');

        $ship = $modes[0];
        $this->assertSame('Delivery', $ship['label'], 'parcel shipping reads as Delivery');
        $this->assertTrue($ship['needsAddress'], 'a shipping order needs an address');
        $this->assertFalse($ship['needsSlot'], 'a shipping order does not need a slot');

        $pickup = $modes[1];
        $this->assertSame('Pickup', $pickup['label']);
        $this->assertTrue($pickup['needsSlot'], 'a pickup is a time, not a place');

        $this->assertSame('In store', $modes[2]['label']);
    }

    public function test_a_restaurant_keeps_the_legacy_trio(): void
    {
        $restaurant = $this->makeStore(null);
        $cfg = $this->config($restaurant, ['on_table' => true, 'takeaway' => true, 'delivery' => false]);

        $this->assertSame(['on-table', 'takeaway'], array_column($cfg['fulfillmentModes'], 'value'),
            'a restaurant still offers the legacy modes, and only the ones its flags allow');
        $this->assertSame('Dine-in', $cfg['fulfillmentModes'][0]['label']);
    }

    public function test_a_goods_order_is_recorded_in_its_own_vocabulary(): void
    {
        $this->checkout()->assertOk()->assertJson(['success' => true]);

        $order = $this->lastOrder();
        $this->assertNotNull($order, 'the order was not created');
        $this->assertSame('ship', $order->type, 'the order is a shipment, not a takeaway');
        $this->assertSame('12 High Street', $order->address, 'the shipping address is recorded');
        $this->assertSame('555-0100', $order->phone_number);
    }

    public function test_a_legacy_takeaway_on_a_goods_store_becomes_an_in_store_collection(): void
    {
        $this->checkout(['ordering-type' => 'takeaway', 'address' => null])
            ->assertOk()->assertJson(['success' => true]);

        $order = $this->lastOrder();
        $this->assertNotNull($order);
        $this->assertSame('in_store', $order->type,
            'a legacy takeaway is mapped onto the vertical\'s equivalent, not stored as food');
    }

    public function test_a_shipping_order_without_an_address_is_refused(): void
    {
        $this->checkout(['address' => null])->assertJson(['success' => false]);

        $this->assertNull($this->lastOrder(), 'no order was placed');
    }

    public function test_an_order_type_the_store_does_not_offer_is_refused(): void
    {
        $this->checkout(['ordering-type' => 'teleport'])->assertJson(['success' => false]);

        $this->assertNull($this->lastOrder(), 'no order was placed');
    }

    public function test_a_restaurant_order_is_untouched(): void
    {
        $restaurant = $this->makeStore(null);
        $this->store = $restaurant;

        $this->checkout(['ordering-type' => 'takeaway', 'address' => null])
            ->assertOk()->assertJson(['success' => true]);

        $order = $this->lastOrder();
        $this->assertNotNull($order);
        $this->assertSame('takeaway', $order->type, 'a restaurant still records a takeaway as a takeaway');
    }

    public function test_a_goods_order_is_tracked_with_the_goods_vocabulary(): void
    {
        config(['settings.fallback_post_template' => 'crave']);

        $this->checkout()->assertOk()->assertJson(['success' => true]);
        $order = $this->lastOrder();
        $this->assertNotNull($order);

        $res = $this->get(route('store.orderTrack', $order->track_token))->assertOk();

        $res->assertDontSee('Preparing your Food', false);
        $res->assertDontSee('The chef is preparing your meal with care.', false);
        $res->assertSee('Fulfillment', false);
        $res->assertSee('Ready to ship', false);
    }

    public function test_a_food_order_still_gets_its_own_theme_s_track_page(): void
    {
        config(['settings.fallback_post_template' => 'crave']);
        $this->store = $this->makeStore(null);

        $this->checkout(['ordering-type' => 'takeaway', 'address' => null])
            ->assertOk()->assertJson(['success' => true]);
        $order = $this->lastOrder();
        $this->assertNotNull($order);

        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertSee('Preparing your Food', false);
    }
}
