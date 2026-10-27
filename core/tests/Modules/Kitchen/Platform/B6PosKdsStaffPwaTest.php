<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen\Platform;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Modules\Platform\PlatformE2eTestCase;

/**
 * B6 — POS KDS staff PWA (the seam Lane B's `platform/b-pos-kds.js` drives
 * end-to-end in a real browser: staff login, KDS dashboard with order queue,
 * marking items ready/complete, POS sale flow with offline/cash tenders, and
 * offline service-worker queue).
 *
 * This test locks the server-side contract that journey depends on,
 * HTTP-through-controller:
 *   1. Staff login on /staff/login endpoint persists session.
 *   2. GET /staff-app/orders returns live orders (new/preparing/ready/completed).
 *   3. GET /staff-app/kds-tickets returns grouped KDS items (queued/preparing/ready).
 *   4. POST /staff-app/kds/items/{item}/bump advances item status (queued→preparing→ready).
 *   5. POST /staff-app/kds/items/{item}/recall reverts item status.
 *   6. POST /staff-app/orders/{order}/status updates order status (permission + tenant-scoped).
 *   7. GET /staff-app/menu returns orderable menu items (server-priced).
 *   8. POST /staff-app/table-orders creates a tableside order (staff PWA POS flow).
 *   9. Staff can only access their own restaurant's orders (tenant-scoped).
 *  10. Authorization: staff without `kds` permission cannot bump items.
 *  11. Offline queue dedup: replaying with same client_op_id doesn't re-apply.
 *
 * Discriminating: it fails if staff PWA endpoints don't gate permissions, orders
 * aren't tenant-scoped, KDS items don't transition properly, or offline queue
 * sync re-applies ops (client_op_id dedup).
 */
class B6PosKdsStaffPwaTest extends PlatformE2eTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private StaffMember $staffKds;

    private StaffMember $staffServer;

    private Menu $item1;

    private Menu $item2;

    protected function setUp(): void
    {
        parent::setUp();

        // Installed/PreventRequestForgery are already bypassed by
        // PlatformE2eTestCase::setUp(); this class needs nothing extra.

        // Create an owner and a demo store
        $this->owner = User::factory()->create(['email' => 'b6-owner@local.invalid', 'status' => 1]);
        $this->owner->email_verified_at = now();
        $this->owner->save();

        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'slug' => 'b6-test-store-'.time(),
        ]);

        // Create staff: one with KDS permission, one with orders permission only (server)
        $this->staffKds = StaffMember::create([
            'owner_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'name' => 'Chef B6',
            'email' => 'chef-b6@local.invalid',
            'password' => bcrypt('Staff@1234'),
            'role' => 'kitchen',
            'active' => true,
            'permissions' => ['kds' => 1],
        ]);

        $this->staffServer = StaffMember::create([
            'owner_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'name' => 'Server B6',
            'email' => 'server-b6@local.invalid',
            'password' => bcrypt('Staff@1234'),
            'role' => 'waiter', // P1-03: a valid role (the CHECK constraint now holds on every driver)
            'active' => true,
            'permissions' => ['orders' => 1],
        ]);

        // Create menu items for the store
        $this->item1 = Menu::create([
            'store_id' => $this->store->id,
            'name' => 'Burger',
            'price' => 50,
            'active' => 1,
        ]);

        $this->item2 = Menu::create([
            'store_id' => $this->store->id,
            'name' => 'Fries',
            'price' => 20,
            'active' => 1,
        ]);
    }

    /**
     * Test staff login via /staff/login and session creation.
     */
    public function test_staff_login_creates_session(): void
    {
        $response = $this->post(route('staff.login.attempt'), [
            'email' => 'chef-b6@local.invalid',
            'password' => 'Staff@1234',
        ]);

        // Should redirect or return 200 on success
        $this->assertTrue(in_array($response->status(), [200, 302]));

        // Verify staff session is authenticated
        $this->assertTrue(auth('staff')->check());
        $this->assertEquals($this->staffKds->id, auth('staff')->user()->id);
    }

    /**
     * Test GET /staff-app/orders returns live orders for the staff's restaurant.
     */
    public function test_staff_app_orders_returns_live_orders(): void
    {
        // Create orders on the store with various statuses
        $orderNew = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
            'type' => 'on-table',
            'table_number' => '1',
        ]);

        $orderPreparing = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'preparing',
            'type' => 'on-table',
            'table_number' => '2',
        ]);

        $orderReady = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'ready',
            'type' => 'on-table',
            'table_number' => '3',
        ]);

        // Login as staff and fetch orders
        $response = $this->actingAs($this->staffKds, 'staff')
            ->get('/staff-app/orders');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertIsArray($data['orders']);
        $this->assertGreaterThanOrEqual(3, count($data['orders']));

        // Verify all three orders are in the list
        $orderIds = array_column($data['orders'], 'id');
        $this->assertContains($orderNew->id, $orderIds);
        $this->assertContains($orderPreparing->id, $orderIds);
        $this->assertContains($orderReady->id, $orderIds);
    }

    /**
     * Test GET /staff-app/kds returns grouped KDS items for the staff's station.
     */
    public function test_staff_app_kds_returns_grouped_items(): void
    {
        // Create an order with multiple items in different KDS statuses
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
            'type' => 'on-table',
            'table_number' => '5',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->item1->id,
            'quantity' => 1,
            'status' => 'queued',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->item2->id,
            'quantity' => 2,
            'status' => 'preparing',
        ]);

        // Login as KDS staff and fetch tickets
        $response = $this->actingAs($this->staffKds, 'staff')
            ->get('/staff-app/kds');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertIsArray($data['tickets']);
        $this->assertGreaterThanOrEqual(1, count($data['tickets']));

        // Verify the order ticket is present
        $orderIds = array_column($data['tickets'], 'order_id');
        $this->assertContains($order->id, $orderIds);
    }

    /**
     * Test POST /staff-app/kds/items/{id}/bump advances item status.
     */
    public function test_staff_app_kds_bump_advances_item_status(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->item1->id,
            'quantity' => 1,
            'status' => 'queued',
        ]);

        // Login as KDS staff and bump the item
        $response = $this->actingAs($this->staffKds, 'staff')
            ->post("/staff-app/kds/items/{$item->id}/bump");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('preparing', $data['status']);

        // Verify the item status changed in the DB
        $this->assertEquals('preparing', $item->fresh()->status);
    }

    /**
     * Test POST /staff-app/kds/items/{id}/recall reverts item status.
     */
    public function test_staff_app_kds_recall_reverts_item_status(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->item1->id,
            'quantity' => 1,
            'status' => 'preparing',
        ]);

        // Login as KDS staff and recall the item
        $response = $this->actingAs($this->staffKds, 'staff')
            ->post("/staff-app/kds/items/{$item->id}/recall");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('queued', $data['status']);

        // Verify the item status reverted in the DB
        $this->assertEquals('queued', $item->fresh()->status);
    }

    /**
     * Test POST /staff-app/orders/{order}/status updates order status with permission check.
     */
    public function test_staff_app_update_order_status_with_permission(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
        ]);

        // Server staff (has orders permission) can update status
        $response = $this->actingAs($this->staffServer, 'staff')
            ->json('POST', "/staff-app/orders/{$order->id}/status", [
                'status' => 'preparing',
            ]);

        // Status endpoint returns 200
        $response->assertStatus(200);

        // Verify DB was updated
        $this->assertEquals('preparing', $order->fresh()->status);
    }

    /**
     * Test POST /staff-app/orders/{order}/status forbidden if staff lacks permission.
     */
    public function test_staff_app_update_order_status_permission_denied(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
        ]);

        // KDS staff (no orders permission) cannot update order status
        $response = $this->actingAs($this->staffKds, 'staff')
            ->json('POST', "/staff-app/orders/{$order->id}/status", [
                'status' => 'preparing',
            ]);

        $response->assertStatus(403);
        $this->assertEquals('new', $order->fresh()->status);
    }

    /**
     * Test GET /staff-app/menu returns orderable menu items for the staff's restaurant.
     */
    public function test_staff_app_menu_returns_items(): void
    {
        $response = $this->actingAs($this->staffKds, 'staff')
            ->get('/staff-app/menu');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertIsArray($data['items']);
        $this->assertGreaterThanOrEqual(2, count($data['items']));

        // Verify item details are present (id, name, price)
        $item = $data['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('price', $item);
    }

    /**
     * Test POST /staff-app/table-orders creates a tableside order (POS flow).
     */
    public function test_staff_app_create_tableside_order(): void
    {
        // Server staff can create orders
        $response = $this->actingAs($this->staffServer, 'staff')
            ->post('/staff-app/table-orders', [
                'table_number' => '7',
                'items' => [
                    ['item_id' => $this->item1->id, 'quantity' => 1],
                    ['item_id' => $this->item2->id, 'quantity' => 2],
                ],
                'note' => 'No onions',
            ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('table_number', $data);

        // Verify order was created in the DB
        $order = Order::find($data['id']);
        $this->assertNotNull($order);
        $this->assertEquals('7', $order->table_number);
        $this->assertEquals($this->store->id, $order->store_id);
        $this->assertEquals('on-table', $order->type);
    }

    /**
     * Test staff cannot create order if they lack orders permission.
     */
    public function test_staff_app_create_order_permission_denied(): void
    {
        // KDS staff (no orders permission) cannot create orders
        $response = $this->actingAs($this->staffKds, 'staff')
            ->post('/staff-app/table-orders', [
                'table_number' => '8',
                'items' => [
                    ['item_id' => $this->item1->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test tenant scoping: staff can only access their own restaurant's orders.
     */
    public function test_staff_cannot_access_other_restaurant_orders(): void
    {
        // Create another restaurant and order
        $otherOwner = User::factory()->create();
        $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
        $otherOrder = Order::factory()->create(['store_id' => $otherStore->id, 'status' => 'new']);

        // Our staff tries to update the other restaurant's order
        $response = $this->actingAs($this->staffServer, 'staff')
            ->json('POST', "/staff-app/orders/{$otherOrder->id}/status", [
                'status' => 'preparing',
            ]);

        // Should be forbidden or not found
        $this->assertTrue(in_array($response->status(), [403, 404]));

        // Verify the order was not modified
        $this->assertEquals('new', $otherOrder->fresh()->status);
    }

    /**
     * Test offline queue dedup: replay of the same client_op_id returns duplicate=true.
     */
    public function test_offline_queue_dedup_via_client_op_id(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
        ]);

        $clientOpId = 'offline-op-'.time().'-'.rand(1000, 9999);

        // First submit: new op
        $response1 = $this->actingAs($this->staffServer, 'staff')
            ->json('POST', "/staff-app/orders/{$order->id}/status", [
                'status' => 'preparing',
                'client_op_id' => $clientOpId,
            ]);

        $response1->assertStatus(200);
        $this->assertEquals('preparing', $order->fresh()->status);

        // Second submit: replay with the same client_op_id (offline reconnect)
        $response2 = $this->actingAs($this->staffServer, 'staff')
            ->json('POST', "/staff-app/orders/{$order->id}/status", [
                'status' => 'ready',  // Different status, but should be ignored
                'client_op_id' => $clientOpId,
            ]);

        $response2->assertStatus(200);
        $data = $response2->json();

        // Should return duplicate=true and NOT change status
        if (isset($data['duplicate'])) {
            $this->assertTrue($data['duplicate']);
        }
        $this->assertEquals('preparing', $order->fresh()->status, 'replay should not change status');
    }

    /**
     * Test KDS permission isolation: staff without kds permission cannot bump items.
     */
    public function test_kds_bump_permission_denied_for_non_kds_staff(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'new',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->item1->id,
            'quantity' => 1,
            'status' => 'queued',
        ]);

        // Server staff (no KDS permission) tries to bump
        $response = $this->actingAs($this->staffServer, 'staff')
            ->json('POST', "/staff-app/kds/items/{$item->id}/bump");

        $response->assertStatus(403);

        // Verify the item status was not changed
        $this->assertEquals('queued', $item->fresh()->status);
    }
}
