<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Covers the expanded management API: menu CRUD, order creation, driver
 * assignment, the driver-app token surface, and public order tracking.
 */
class ApiManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'Plan '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function token(User $user, array $abilities = ['read', 'write']): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id, 'name' => 'T',
            'token' => hash('sha256', $raw), 'abilities' => $abilities,
        ]);

        return $raw;
    }

    private function item(Store $store, float $price = 10, array $extra = []): Menu
    {
        return Menu::create(array_merge([
            'store_id' => $store->id, 'name' => 'Dish '.Str::random(4),
            'price' => $price, 'active' => 1,
        ], $extra));
    }

    // ── Menu CRUD ─────────────────────────────────────────────────────────────

    public function test_delete_category_refuses_when_items_exist(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains']);
        $this->item($r, 9, ['category_id' => $cat->id]);
        $token = $this->token($user);

        // has items → 409, nothing deleted
        $this->withToken($token)
            ->deleteJson(route('api.v1.menu.categories.delete', [$r->id, $cat->id]))
            ->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $cat->id]);

        // empty category → deletes cleanly
        $empty = MenuCategory::create(['store_id' => $r->id, 'name' => 'Empty']);
        $this->withToken($token)
            ->deleteJson(route('api.v1.menu.categories.delete', [$r->id, $empty->id]))
            ->assertOk()->assertJson(['deleted' => true]);
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
    }

    public function test_owner_can_create_and_update_menu_item(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains']);

        $create = $this->withToken($this->token($user))
            ->postJson(route('api.v1.menu.items.store', $r->id), [
                'name' => 'Burger', 'price' => 12.5, 'category_id' => $cat->id,
            ])->assertCreated()->json();

        $this->assertDatabaseHas('items', ['id' => $create['id'], 'store_id' => $r->id]);

        $this->withToken($this->token($user))
            ->putJson(route('api.v1.menu.items.update', [$r->id, $create['id']]), ['price' => 9.99])
            ->assertOk()->assertJsonPath('price', 9.99);
    }

    public function test_sold_out_toggle_flips(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $item = $this->item($r);

        $this->withToken($this->token($user))
            ->patchJson(route('api.v1.menu.items.soldout', [$r->id, $item->id]))
            ->assertOk()->assertJsonPath('is_sold_out', true);
    }

    public function test_read_only_token_cannot_create_item(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);

        $this->withToken($this->token($user, ['read']))
            ->postJson(route('api.v1.menu.items.store', $r->id), ['name' => 'X', 'price' => 1])
            ->assertForbidden();
    }

    public function test_cannot_touch_another_owners_restaurant(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($other);

        $this->withToken($this->token($owner))
            ->postJson(route('api.v1.menu.items.store', $otherRestaurant->id), ['name' => 'X', 'price' => 1])
            ->assertNotFound();
    }

    // ── Order creation ────────────────────────────────────────────────────────

    public function test_create_order_builds_items_and_fires_webhook(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $a = $this->item($r, 10);
        $b = $this->item($r, 5);

        $res = $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.store', $r->id), [
                'type' => 'takeaway', 'customer_name' => 'Sam',
                'items' => [
                    ['item_id' => $a->id, 'quantity' => 2],
                    ['item_id' => $b->id, 'quantity' => 1],
                ],
            ])->assertCreated()->json();

        // API orders now sync to the KDS ticket status 'new' (not left at 'pending')
        // so they actually reach the kitchen — verified by the integration smoke.
        $this->assertDatabaseHas('orders', ['id' => $res['id'], 'store_id' => $r->id, 'status' => 'new']);
        $this->assertCount(2, $res['items']);
        $this->assertNotEmpty($res['track_token']);
        // 2×10 + 1×5 = 25 (no tax/delivery configured)
        $this->assertEquals(25, $res['total']);
    }

    public function test_create_order_rejects_sold_out_item(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $item = $this->item($r, 10, ['is_sold_out' => 1]);

        $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.store', $r->id), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertStatus(422);
    }

    public function test_create_order_rejects_foreign_item(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $foreign = $this->item($this->makeRestaurant($this->makeUser()), 10);

        $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.store', $r->id), [
                'type' => 'takeaway',
                'items' => [['item_id' => $foreign->id, 'quantity' => 1]],
            ])->assertStatus(422);
    }

    // ── Drivers + delivery lifecycle ────────────────────────────────────────────

    public function test_driver_assignment_and_driver_app_flow(): void
    {
        /* F1-1 (gap audit): the REST driver block now carries `feature:driver_app`,
           matching the web dispatch routes. driver_app's registry default is OFF, so
           this happy-path flow needs the superadmin kill-switch ON — exactly what a
           real deployment gets from PremiumBundleSeeder. The gate matrix itself lives
           in FeaturePlatformFix1DriverApiTest. */
        config(['settings.admin_allow_driver_app' => 1]);
        FeatureRegistry::clearCache();

        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $item = $this->item($r, 10);

        // Create a delivery order via the API
        $order = $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.store', $r->id), [
                'type' => 'delivery', 'customer_name' => 'Sam', 'address' => '9 Road',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertCreated()->json();

        // Create a driver — raw token returned once
        $driver = $this->withToken($this->token($user))
            ->postJson(route('api.v1.drivers.store'), ['name' => 'Alex'])
            ->assertCreated()->json();
        $this->assertArrayHasKey('token', $driver);
        $driverToken = $driver['token'];

        // Assign the driver
        $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.assign', [$r->id, $order['id']]), ['driver_id' => $driver['id']])
            ->assertOk()->assertJsonPath('delivery_status', 'assigned');

        // Driver app: sees the delivery
        $this->withToken($driverToken)
            ->getJson(route('api.v1.driver.deliveries'))
            ->assertOk()->assertJsonPath('data.0.id', $order['id']);

        // Driver marks out for delivery, then delivered
        $this->withToken($driverToken)
            ->putJson(route('api.v1.driver.deliveries.status', $order['id']), ['delivery_status' => 'out_for_delivery'])
            ->assertOk()->assertJsonPath('delivery_status', 'out_for_delivery');

        $this->withToken($driverToken)
            ->putJson(route('api.v1.driver.deliveries.status', $order['id']), ['delivery_status' => 'delivered'])
            ->assertOk()->assertJsonPath('delivery_status', 'delivered');

        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'delivery_status' => 'delivered']);
        $this->assertNotNull(Order::find($order['id'])->delivered_at);
    }

    public function test_driver_cannot_touch_unassigned_order(): void
    {
        /* F6-6 (gap audit): the driver-TOKEN block (`DriverTokenAuth`) now carries
           `feature:driver_app` too, not just the driver-admin CRUD that F1-1 closed.
           driver_app's registry default is OFF, so without the kill-switch this test
           403s at the gate and never reaches its actual subject — that a driver
           cannot touch an order it was not assigned. Same setup as the happy-path
           sibling above; the gate matrix itself lives in FeaturePlatformFix6ParitySweepTest. */
        config(['settings.admin_allow_driver_app' => 1]);
        FeatureRegistry::clearCache();

        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $item = $this->item($r, 10);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'created_at' => now()]);

        $raw = Str::random(48);
        Driver::create(['owner_id' => $user->id, 'name' => 'A', 'token' => hash('sha256', $raw), 'active' => true]);

        $this->withToken($raw)
            ->putJson(route('api.v1.driver.deliveries.status', $order->id), ['delivery_status' => 'delivered'])
            ->assertNotFound();
    }

    public function test_invalid_driver_token_rejected(): void
    {
        $this->withToken('nope')
            ->getJson(route('api.v1.driver.me'))
            ->assertUnauthorized();
    }

    // ── Public tracking ─────────────────────────────────────────────────────────

    public function test_public_track_by_token(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $item = $this->item($r, 10);
        $order = $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.store', $r->id), [
                'type' => 'delivery', 'address' => '9 Road',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->json();

        $this->getJson(route('api.v1.track', $order['track_token']))
            ->assertOk()
            ->assertJsonPath('id', $order['id'])
            ->assertJsonPath('delivery_status', 'pending');

        $this->getJson(route('api.v1.track', 'bogustoken'))->assertNotFound();
    }

    // ── Analytics ────────────────────────────────────────────────────────────────

    public function test_analytics_summary_totals(): void
    {
        $user = $this->makeUser();
        $r = $this->makeRestaurant($user);
        $item = $this->item($r, 10);
        $this->withToken($this->token($user))
            ->postJson(route('api.v1.orders.store', $r->id), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 3]],
            ])->assertCreated();

        $this->withToken($this->token($user))
            ->getJson(route('api.v1.analytics.summary', $r->id))
            ->assertOk()
            ->assertJsonPath('orders.total', 1)
            ->assertJsonPath('revenue.gross', 30);
    }
}
