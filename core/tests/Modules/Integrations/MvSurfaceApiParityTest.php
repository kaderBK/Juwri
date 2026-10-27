<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontToken;
use App\Models\VehicleFitment;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/mv-surface-api P0/P1 — the REST surface must speak every vertical, not
 * just restaurants. Guarantees:
 *  1. The customer-app order endpoint accepts a non-food fulfillment mode
 *     (ship/pickup_slot…) for a goods store — it used to hard 422 (P0).
 *  2. store_type + vertical metadata is exposed on the storefront-token and
 *     public read surfaces (parity with the owner-token endpoint).
 *  3. The public + owner menu payloads carry the goods attributes a non-food
 *     store actually has (OTC expiry/age, car-parts fitment) instead of only
 *     food fields.
 *  4. Order track exposes the fulfillment-mode label + the goods shipment.
 */
class MvSurfaceApiParityTest extends TestCase
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

    private function store(string $type = 'restaurant'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'storefront_api' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $store, array $extra = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat', 'active' => 1]);

        return Menu::create(array_merge([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Thing '.Str::random(4), 'price' => 9, 'pricing_unit' => 'each', 'active' => 1,
        ], $extra));
    }

    private function apiToken(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 'T', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $raw;
    }

    private function storefrontToken(Store $store): string
    {
        $raw = Str::random(48);
        StorefrontToken::create(['store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => ['menu:read'], 'active' => true]);

        return $raw;
    }

    private function customerToken(Store $store): string
    {
        return $this->postJson(route('api.v1.customer.register', $store->id), [
            'name' => 'Sam', 'email' => Str::lower(Str::random(6)).'@ex.com', 'password' => 'secret123',
        ])->assertCreated()->json('token');
    }

    // ── P0: customer-app order accepts non-food modes ───────────────────────────

    public function test_customer_app_places_a_ship_order_on_a_goods_store(): void
    {
        $store = $this->store('retail');
        $item = $this->item($store);
        $token = $this->customerToken($store);

        // 'ship' is a retail fulfillment mode but NOT a food literal — before the
        // fix this 422'd on the hardcoded in:on-table,takeaway,delivery rule.
        $this->assertContains('ship', app(FulfillmentRegistry::class)->modesFor('retail'));

        $order = $this->withToken($token)
            ->postJson(route('api.v1.customer.orders.place'), [
                'type' => 'ship', 'address' => '1 Test St',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertCreated()->json();

        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'store_id' => $store->id, 'type' => 'ship']);
    }

    public function test_customer_app_still_rejects_a_non_food_mode_on_a_restaurant(): void
    {
        $store = $this->store('restaurant');
        $item = $this->item($store, ['type' => 'veg']);
        $token = $this->customerToken($store);

        // A restaurant's fulfillment ceiling is food-only, so 'ship' stays invalid.
        $this->withToken($token)
            ->postJson(route('api.v1.customer.orders.place'), [
                'type' => 'ship', 'address' => '1 Test St',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertStatus(422);
    }

    // ── P1: store_type parity on read surfaces ──────────────────────────────────

    public function test_storefront_token_restaurant_endpoint_exposes_store_type(): void
    {
        $store = $this->store('pharmacy');
        $raw = $this->storefrontToken($store);

        $this->getJson(route('api.v1.storefront.restaurant'), ['X-Storefront-Token' => $raw])
            ->assertOk()
            ->assertJsonPath('store_type', 'pharmacy')
            ->assertJsonPath('vertical.group', 'goods')
            ->assertJsonStructure(['id', 'store_type', 'vertical' => ['label', 'capabilities', 'fulfillment_modes']]);
    }

    public function test_public_menu_exposes_goods_attributes_for_a_non_food_store(): void
    {
        $store = $this->store('pharmacy');
        PharmacyMarket::open($store); // P1-23 (AUP 3.1): only a published pharmacy (enabled market, approved licence) serves its menu
        $this->item($store, ['age_restricted' => true, 'expiry_date' => now()->addYear()->toDateString(), 'batch_no' => 'LOT-9']);

        $json = $this->getJson(route('api.v1.public.menu', $store->id))->assertOk()->json();

        $this->assertSame('pharmacy', $json['store']['store_type']);
        $row = $json['categories'][0]['items'][0];
        $this->assertArrayHasKey('age_restricted', $row);
        $this->assertTrue($row['age_restricted']);
        $this->assertArrayHasKey('expiry_date', $row);
        $this->assertSame('LOT-9', $row['batch_no']);
        // Food-only keys must NOT leak onto a pharmacy item.
        $this->assertArrayNotHasKey('is_vegan', $row);
    }

    public function test_public_menu_keeps_food_attributes_for_a_restaurant(): void
    {
        $store = $this->store('restaurant');
        $this->item($store, ['type' => 'veg', 'is_vegan' => 1, 'calories' => 250]);

        $row = $this->getJson(route('api.v1.public.menu', $store->id))
            ->assertOk()->json('categories.0.items.0');

        $this->assertArrayHasKey('is_vegan', $row);
        $this->assertTrue($row['is_vegan']);
        $this->assertArrayNotHasKey('age_restricted', $row);
    }

    public function test_owner_menu_includes_fitments_for_a_car_parts_store(): void
    {
        $store = $this->store('carparts');
        $item = $this->item($store);
        VehicleFitment::create([
            'menu_id' => $item->id, 'store_id' => $store->id,
            'year' => 2016, 'make' => 'Honda', 'model' => 'Civic',
        ]);

        $row = $this->withToken($this->apiToken($store->user))
            ->getJson(route('api.v1.stores.menu', $store->id))
            ->assertOk()->json('0.items.0');

        $this->assertArrayHasKey('fitments', $row);
        $this->assertSame('Honda', $row['fitments'][0]['make']);
        $this->assertSame(2016, $row['fitments'][0]['year']);
    }

    // ── P1: order track exposes fulfillment label + shipment ────────────────────

    public function test_track_exposes_type_label_and_shipment_for_a_ship_order(): void
    {
        $store = $this->store('retail');
        $item = $this->item($store);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'ship', 'customer_name' => 'Sam',
            'phone_number' => '555', 'address' => '1 St', 'status' => 'pending', 'seen' => 0,
            'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        Shipment::create([
            'order_id' => $order->id, 'status' => 'shipped',
            'carrier' => 'Aramex', 'tracking_number' => 'TRK123',
        ]);

        $json = $this->getJson(route('api.v1.track', $order->track_token))->assertOk()->json();

        $this->assertSame('ship', $json['type']);
        $this->assertSame(app(FulfillmentRegistry::class)->label('ship'), $json['type_label']);
        $this->assertSame('retail', $json['store_type']);
        $this->assertSame('Aramex', $json['shipment']['carrier']);
        $this->assertSame('TRK123', $json['shipment']['tracking_number']);
    }
}
