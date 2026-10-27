<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Models\ActivityLog;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\StaffTask;
use Juwri\Modules\Hr\Models\StaffTaskCompletion;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Team\Models\StaffMessage;
use Juwri\Modules\Team\Models\StaffSyncedOp;
use Tests\TestCase;

class StaffAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function makeRestaurant(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function makeStaff(User $owner, Store $store, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Staff', 'role' => $role,
            'email' => 'staff'.Str::random(5).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
    }

    public function test_orders_feed_is_scoped_to_staff_restaurant(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store);

        Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        Order::create(['store_id' => $store->id, 'status' => 'completed', 'type' => 'takeaway']); // excluded
        Order::create(['store_id' => $otherPost->id, 'status' => 'new', 'type' => 'takeaway']);  // other tenant

        $res = $this->actingAs($staff, 'staff')->getJson('/staff-app/orders');

        $res->assertOk();
        $this->assertCount(1, $res->json('orders'));
        $this->assertSame('new', $res->json('orders.0.status'));
    }

    public function test_staff_can_advance_status_with_permission(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/status", ['status' => 'preparing'])
            ->assertOk()->assertJsonPath('status', 'preparing');

        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->preparing_at);
    }

    /** SP2: a replayed offline op (same client_op_id) applies exactly once. */
    public function test_replayed_offline_op_is_idempotent(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        $op = ['status' => 'ready', 'client_op_id' => 'op-abc-123'];

        $this->actingAs($staff, 'staff')->postJson("/staff-app/orders/{$order->id}/status", $op)
            ->assertOk()->assertJsonPath('status', 'ready');
        $readyAt = $order->fresh()->ready_at;

        // replay the same op after "reconnect" — must be a no-op, not a second apply
        $res = $this->actingAs($staff, 'staff')->postJson("/staff-app/orders/{$order->id}/status", $op)->assertOk();
        $this->assertTrue($res->json('duplicate'));

        $this->assertEquals($readyAt, $order->fresh()->ready_at); // timestamp not re-stamped
        $this->assertSame(1, StaffSyncedOp::where('op_id', 'op-abc-123')->count());
    }

    /** SP3 #13: rejecting an incoming order cancels it and records the reason for audit. */
    public function test_reject_cancels_order_and_logs_the_reason(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'kitchen closed'])
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', $order->fresh()->status);

        $log = ActivityLog::where('action', 'order.status')
            ->where('subject_id', $order->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('kitchen closed', $log->meta['reason'] ?? null);
    }

    /** SP4 #18: staff creates a tableside order, priced server-side, attributed to them. */
    public function test_tableside_order_is_created_server_priced(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 9, 'active' => 1]);

        $res = $this->actingAs($staff, 'staff')->postJson('/staff-app/table-orders', [
            'table_number' => '7',
            // forged price is not even in the contract — server prices from the DB
            'items' => [['item_id' => $item->id, 'quantity' => 2, 'price' => 0]],
        ])->assertStatus(201);

        $order = Order::find($res->json('id'));
        $this->assertSame('on-table', $order->type);
        $this->assertSame('7', (string) $order->table_number);
        $this->assertSame('staff_pwa', $order->source);
        $this->assertSame($staff->id, (int) $order->created_by_staff_id);
        // priced from the DB (Order->total re-derives from live Menu): 9 × 2 = 18
        $this->assertEquals(18.0, (float) $order->total);
        $this->assertEquals(2, (int) $order->items()->first()->quantity);
    }

    /** SP4 #18: creating a tableside order needs the orders permission. */
    public function test_tableside_order_requires_orders_permission(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'kitchen'); // no 'orders'
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Fries', 'price' => 4, 'active' => 1]);

        $this->actingAs($staff, 'staff')->postJson('/staff-app/table-orders', [
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(403);
    }

    /** SP4 #18: the create-screen menu is scoped to the staff's restaurant. */
    public function test_menu_endpoint_is_scoped(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store);
        $catA = MenuCategory::create(['store_id' => $store->id, 'name' => 'A', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $catA->id, 'name' => 'Mine', 'price' => 5, 'active' => 1]);
        $catB = MenuCategory::create(['store_id' => $otherPost->id, 'name' => 'B', 'active' => 1]);
        Menu::create(['store_id' => $otherPost->id, 'category_id' => $catB->id, 'name' => 'Theirs', 'price' => 5, 'active' => 1]);

        $res = $this->actingAs($staff, 'staff')->getJson('/staff-app/menu')->assertOk();
        $names = array_column($res->json('items'), 'name');
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    /** SP4 #21: requesting the bill marks the order call_bill and keeps it in the feed. */
    public function test_request_bill_sets_call_bill_and_stays_in_feed(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $order = Order::create(['store_id' => $store->id, 'status' => 'ready', 'type' => 'on-table', 'table_number' => '5']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/request-bill")
            ->assertOk()->assertJsonPath('status', 'call_bill');
        $this->assertSame('call_bill', $order->fresh()->status);

        // still visible in the live feed (not dropped like completed/cancelled)
        $feed = $this->actingAs($staff, 'staff')->getJson('/staff-app/orders')->json('orders');
        $this->assertContains($order->id, array_column($feed, 'id'));
    }

    public function test_request_bill_needs_orders_permission(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $kitchen = $this->makeStaff($owner, $store, 'kitchen');
        $order = Order::create(['store_id' => $store->id, 'status' => 'ready', 'type' => 'on-table', 'table_number' => '5']);

        $this->actingAs($kitchen, 'staff')->postJson("/staff-app/orders/{$order->id}/request-bill")->assertStatus(403);
    }

    public function test_request_bill_cannot_touch_another_restaurants_order(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $mgr = $this->makeStaff($owner, $store, 'manager');
        $foreign = Order::create(['store_id' => $otherPost->id, 'status' => 'ready', 'type' => 'on-table']);

        $this->actingAs($mgr, 'staff')->postJson("/staff-app/orders/{$foreign->id}/request-bill")->assertStatus(404) /* P1-04 slice 5: the tenant scope hides a foreign store's order (was 403) */;
    }

    /** SP4 #19: the phone floor snapshot is scoped and reflects live occupancy + bill flag. */
    public function test_floor_snapshot_is_scoped_with_occupancy(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 5, 'seats' => 4, 'status' => 'free']);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 6, 'seats' => 2, 'status' => 'free']);
        Order::create(['store_id' => $store->id, 'status' => 'call_bill', 'type' => 'on-table', 'table_number' => '5']);

        $tables = $this->actingAs($staff, 'staff')->getJson('/staff-app/floor')->assertOk()->json('tables');
        $this->assertCount(2, $tables);
        $t5 = collect($tables)->firstWhere('table_no', 5);
        $this->assertSame('call_bill', $t5['status']);
        $this->assertTrue($t5['bill']);
        $t6 = collect($tables)->firstWhere('table_no', 6);
        $this->assertSame('free', $t6['status']);
    }

    /** SP4 #22: move an order to another table. */
    public function test_transfer_moves_order_to_another_table(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $order = Order::create(['store_id' => $store->id, 'status' => 'preparing', 'type' => 'on-table', 'table_number' => '3']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/transfer", ['table_number' => '8'])
            ->assertOk()->assertJsonPath('table_number', '8');

        $this->assertSame('8', (string) $order->fresh()->table_number);
    }

    public function test_transfer_requires_permission(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $kitchen = $this->makeStaff($owner, $store, 'kitchen');
        $order = Order::create(['store_id' => $store->id, 'status' => 'preparing', 'type' => 'on-table', 'table_number' => '3']);

        $this->actingAs($kitchen, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/transfer", ['table_number' => '8'])
            ->assertStatus(403);
    }

    public function test_transfer_cannot_touch_another_restaurants_order(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $foreign = Order::create(['store_id' => $otherPost->id, 'status' => 'preparing', 'type' => 'on-table', 'table_number' => '3']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$foreign->id}/transfer", ['table_number' => '8'])
            ->assertStatus(404) /* P1-04 slice 5: the tenant scope hides a foreign store's order (was 403) */;
    }

    /** FP7 #42: a server can see only the tables assigned to them. */
    public function test_floor_mine_filter_returns_only_assigned_tables(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $server = $this->makeStaff($owner, $store, 'manager');
        $other = $this->makeStaff($owner, $store, 'manager');
        StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'seats' => 4, 'status' => 'free', 'server_staff_id' => $server->id, 'section' => 'Patio', 'note' => 'VIP']);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 2, 'seats' => 4, 'status' => 'free', 'server_staff_id' => $other->id]);

        $all = $this->actingAs($server, 'staff')->getJson('/staff-app/floor')->assertOk()->json('tables');
        $this->assertCount(2, $all);
        $t1 = collect($all)->firstWhere('table_no', 1);
        $this->assertTrue($t1['mine']);
        $this->assertSame('Patio', $t1['section']);
        $this->assertSame('VIP', $t1['note']); // FP8 #46

        $mine = $this->actingAs($server, 'staff')->getJson('/staff-app/floor?mine=1')->assertOk()->json('tables');
        $this->assertCount(1, $mine);
        $this->assertSame(1, (int) $mine[0]['table_no']);
    }

    /** SP5 #24/#25: KDS tickets are tenant-scoped, grouped per order, with all-day counts. */
    public function test_kds_tickets_are_scoped_grouped_with_all_day(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $kitchen = $this->makeStaff($owner, $store, 'kitchen'); // has 'kds' only

        $catA = MenuCategory::create(['store_id' => $store->id, 'name' => 'A', 'active' => 1]);
        $burger = Menu::create(['store_id' => $store->id, 'category_id' => $catA->id, 'name' => 'Burger', 'price' => 9, 'active' => 1]);
        $fries = Menu::create(['store_id' => $store->id, 'category_id' => $catA->id, 'name' => 'Fries', 'price' => 4, 'active' => 1]);

        $order = Order::create(['store_id' => $store->id, 'status' => 'preparing', 'type' => 'on-table', 'table_number' => '4']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $burger->id, 'quantity' => 2, 'status' => 'queued']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $fries->id, 'quantity' => 1, 'status' => 'preparing']);
        // a completed item is not on the board
        OrderItem::create(['order_id' => $order->id, 'item_id' => $fries->id, 'quantity' => 5, 'status' => 'served']);
        // another tenant's open item must never appear
        $foreign = Order::create(['store_id' => $otherPost->id, 'status' => 'preparing', 'type' => 'takeaway']);
        $fcat = MenuCategory::create(['store_id' => $otherPost->id, 'name' => 'F', 'active' => 1]);
        $fitem = Menu::create(['store_id' => $otherPost->id, 'category_id' => $fcat->id, 'name' => 'Secret', 'price' => 1, 'active' => 1]);
        OrderItem::create(['order_id' => $foreign->id, 'item_id' => $fitem->id, 'quantity' => 9, 'status' => 'queued']);

        $res = $this->actingAs($kitchen, 'staff')->getJson('/staff-app/kds')->assertOk();

        $tickets = $res->json('tickets');
        $this->assertCount(1, $tickets);                       // one order, one ticket
        $this->assertSame($order->id, $tickets[0]['order_id']);
        $this->assertCount(2, $tickets[0]['items']);           // served item excluded

        $allDay = collect($res->json('all_day'))->keyBy('name');
        $this->assertSame(2, $allDay['Burger']['qty']);
        $this->assertSame(1, $allDay['Fries']['qty']);         // only the open Fries, not the served 5
        $this->assertArrayNotHasKey('Secret', $allDay->all()); // no cross-tenant leakage
    }

    /** SP5 #24: the PWA shell serves the Kitchen view + KDS wiring (real blade compile). */
    public function test_shell_renders_kitchen_view(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'kitchen');

        $html = $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->getContent();
        $this->assertStringContainsString('data-v="kds"', $html);   // the Kitchen chip
        $this->assertStringContainsString('id="kds"', $html);       // the KDS container
        $this->assertStringContainsString('/staff-app/kds', $html); // the fetch wiring
        $this->assertStringContainsString('function loadKds', $html);
        // SP8: Tasks view is wired too
        $this->assertStringContainsString('data-v="tasks"', $html);
        $this->assertStringContainsString('/staff-app/tasks', $html);
        $this->assertStringContainsString('function loadTasks', $html);
    }

    /** SP5 #27: bump advances an item through the KDS pipeline; recall steps it back. */
    public function test_kds_bump_and_recall_move_the_item(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $kitchen = $this->makeStaff($owner, $store, 'kitchen');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'A', 'active' => 1]);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Soup', 'price' => 5, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'status' => 'preparing', 'type' => 'takeaway']);
        $it = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'status' => 'queued']);

        $this->actingAs($kitchen, 'staff')->postJson("/staff-app/kds/items/{$it->id}/bump")
            ->assertOk()->assertJsonPath('status', 'preparing');
        $this->assertSame('preparing', $it->fresh()->status);

        $this->actingAs($kitchen, 'staff')->postJson("/staff-app/kds/items/{$it->id}/recall")
            ->assertOk()->assertJsonPath('status', 'queued');
        $this->assertSame('queued', $it->fresh()->status);
    }

    /** SP5: KDS needs the 'kds' permission (a waiter has orders but not kds). */
    public function test_kds_requires_kds_permission(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $waiter = $this->makeStaff($owner, $store, 'waiter'); // orders+pos, no kds
        $this->actingAs($waiter, 'staff')->getJson('/staff-app/kds')->assertStatus(403);
    }

    /** SP5: bump cannot touch another restaurant's item (tenant scope on the item lookup). */
    public function test_kds_bump_cannot_touch_another_restaurants_item(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [$otherOwner, $otherPost] = $this->makeRestaurant();
        $kitchen = $this->makeStaff($owner, $store, 'kitchen');
        $fcat = MenuCategory::create(['store_id' => $otherPost->id, 'name' => 'F', 'active' => 1]);
        $fitem = Menu::create(['store_id' => $otherPost->id, 'category_id' => $fcat->id, 'name' => 'X', 'price' => 1, 'active' => 1]);
        $foreign = Order::create(['store_id' => $otherPost->id, 'status' => 'preparing', 'type' => 'takeaway']);
        $fit = OrderItem::create(['order_id' => $foreign->id, 'item_id' => $fitem->id, 'quantity' => 1, 'status' => 'queued']);

        $this->actingAs($kitchen, 'staff')->postJson("/staff-app/kds/items/{$fit->id}/bump")->assertStatus(404);
    }

    /** SP8 #37: the checklist is role-scoped — a staffer sees their-role + everyone tasks only. */
    public function test_tasks_are_role_and_tenant_scoped(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $waiter = $this->makeStaff($owner, $store, 'waiter');

        StaffTask::create(['store_id' => $store->id, 'title' => 'Wipe tables', 'role' => 'waiter', 'position' => 1]);
        StaffTask::create(['store_id' => $store->id, 'title' => 'Lock up', 'role' => null, 'position' => 2]);   // everyone
        StaffTask::create(['store_id' => $store->id, 'title' => 'Clean fryer', 'role' => 'kitchen', 'position' => 3]); // not waiter
        StaffTask::create(['store_id' => $store->id, 'title' => 'Inactive', 'role' => null, 'active' => false]);
        StaffTask::create(['store_id' => $otherPost->id, 'title' => 'Foreign', 'role' => null]);

        $titles = array_column($this->actingAs($waiter, 'staff')->getJson('/staff-app/tasks')->assertOk()->json('tasks'), 'title');
        $this->assertSame(['Wipe tables', 'Lock up'], $titles); // role + everyone, ordered; kitchen/inactive/foreign excluded
    }

    /** SP8 #36: ticking a task records who/when and is idempotent per business-date. */
    public function test_task_completion_is_recorded_and_idempotent(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'waiter');
        $task = StaffTask::create(['store_id' => $store->id, 'title' => 'Set tables', 'role' => null]);

        $this->actingAs($staff, 'staff')->postJson("/staff-app/tasks/{$task->id}/complete")
            ->assertOk()->assertJsonPath('done', true);
        // a second tick the same day must not create a duplicate row
        $this->actingAs($staff, 'staff')->postJson("/staff-app/tasks/{$task->id}/complete")->assertOk();
        $this->assertSame(1, StaffTaskCompletion::where('staff_task_id', $task->id)->count());

        $row = StaffTaskCompletion::where('staff_task_id', $task->id)->first();
        $this->assertSame($staff->id, (int) $row->staff_id);
        $this->assertNotNull($row->completed_at);

        // the list now reflects done=true with attribution
        $listed = collect($this->actingAs($staff, 'staff')->getJson('/staff-app/tasks')->json('tasks'))->firstWhere('id', $task->id);
        $this->assertTrue($listed['done']);
        $this->assertSame($staff->id, (int) $listed['by_staff_id']);

        // undo clears today's tick
        $this->actingAs($staff, 'staff')->postJson("/staff-app/tasks/{$task->id}/uncomplete")->assertOk()->assertJsonPath('done', false);
        $this->assertSame(0, StaffTaskCompletion::where('staff_task_id', $task->id)->count());
    }

    /** SP8 #37: a manager defines a checklist item; it's tenant-stamped. */
    public function test_manager_can_create_task(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $manager = $this->makeStaff($owner, $store, 'manager');

        $res = $this->actingAs($manager, 'staff')->postJson('/staff-app/tasks', ['title' => 'Count float', 'role' => 'cashier', 'section' => 'opening'])
            ->assertStatus(201);
        $task = StaffTask::find($res->json('id'));
        $this->assertSame($store->id, (int) $task->store_id);
        $this->assertSame('cashier', $task->role);
    }

    /** SP8 #37: a non-manager cannot define checklist items. */
    public function test_non_manager_cannot_create_task(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $waiter = $this->makeStaff($owner, $store, 'waiter');
        $this->actingAs($waiter, 'staff')->postJson('/staff-app/tasks', ['title' => 'X'])->assertStatus(403);
    }

    /** SP8: completing another restaurant's task is impossible (scoped lookup 404s). */
    public function test_cannot_complete_another_restaurants_task(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'waiter');
        $foreign = StaffTask::create(['store_id' => $otherPost->id, 'title' => 'Foreign', 'role' => null]);

        $this->actingAs($staff, 'staff')->postJson("/staff-app/tasks/{$foreign->id}/complete")->assertStatus(404);
    }

    /** SP11 #49: the orders feed is scoped to the requested branch; legacy null-branch shows on default. */
    public function test_orders_feed_is_branch_scoped(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        // makeRestaurant seeds a default branch; add one more.
        $default = Branch::where('store_id', $store->id)->orderBy('id')->firstOrFail();
        $downtown = Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        $staff = $this->makeStaff($owner, $store, 'manager');

        Order::create(['store_id' => $store->id, 'branch_id' => $default->id, 'status' => 'new', 'type' => 'takeaway']);
        Order::create(['store_id' => $store->id, 'branch_id' => $downtown->id, 'status' => 'new', 'type' => 'takeaway']);
        Order::create(['store_id' => $store->id, 'branch_id' => null, 'status' => 'new', 'type' => 'takeaway']); // legacy → default

        // Downtown branch sees only its own order.
        $dt = $this->actingAs($staff, 'staff')->getJson('/staff-app/orders?branch='.$downtown->id)->assertOk()->json('orders');
        $this->assertCount(1, $dt);

        // The default branch sees its own + the legacy null-branch order.
        $mainFeed = $this->actingAs($staff, 'staff')->getJson('/staff-app/orders?branch='.$default->id)->assertOk()->json('orders');
        $this->assertCount(2, $mainFeed);
    }

    /** SP11 #49: a branch from another restaurant is rejected. */
    public function test_orders_feed_rejects_foreign_branch(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $foreign = Branch::create(['store_id' => $otherPost->id, 'name' => 'Foreign']);

        $this->actingAs($staff, 'staff')->getJson('/staff-app/orders?branch='.$foreign->id)->assertStatus(404);
    }

    /** SP11 #49: the branches endpoint lists the restaurant's branches + the active one. */
    public function test_branches_endpoint_lists_and_scopes(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $b = Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        Branch::create(['store_id' => $otherPost->id, 'name' => 'Other']); // not visible
        $staff = $this->makeStaff($owner, $store, 'manager');

        $res = $this->actingAs($staff, 'staff')->getJson('/staff-app/branches')->assertOk();
        $names = array_column($res->json('branches'), 'name');
        $this->assertContains('Downtown', $names);
        $this->assertNotContains('Other', $names);       // no cross-tenant leakage
        $this->assertNull($res->json('active'));          // staff has no branch_id and no ?branch
    }

    /** SP11 #49: a staff pinned to a branch reports it as the active branch. */
    public function test_branches_active_reflects_staff_branch(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $b = Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        $staff = $this->makeStaff($owner, $store, 'manager');
        $staff->update(['branch_id' => $b->id]);

        $this->assertSame($b->id, $this->actingAs($staff, 'staff')->getJson('/staff-app/branches')->assertOk()->json('active'));
    }

    /** SP9 #41: any staff can post to the thread; it's tenant-scoped on read. */
    public function test_staff_can_post_and_read_messages_scoped(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'waiter');

        $this->actingAs($staff, 'staff')->postJson('/staff-app/messages', ['body' => 'Table 4 needs water'])->assertStatus(201);
        StaffMessage::create(['store_id' => $otherPost->id, 'body' => 'Foreign', 'kind' => 'message']);

        $msgs = $this->actingAs($staff, 'staff')->getJson('/staff-app/messages')->assertOk()->json('messages');
        $this->assertCount(1, $msgs); // only this restaurant's thread
        $this->assertSame('Table 4 needs water', $msgs[0]['body']);
        $this->assertTrue($msgs[0]['mine']);
    }

    /** SP9 #42: a manager broadcast is stored as kind=broadcast and fires the push job. */
    public function test_manager_broadcast_posts_and_dispatches_push(): void
    {
        Queue::fake();
        [$owner, $store] = $this->makeRestaurant();
        $manager = $this->makeStaff($owner, $store, 'manager');

        $this->actingAs($manager, 'staff')->postJson('/staff-app/broadcast', ['body' => 'All hands at 5pm'])
            ->assertStatus(201)->assertJsonPath('kind', 'broadcast');

        $this->assertDatabaseHas('staff_messages', ['store_id' => $store->id, 'kind' => 'broadcast', 'body' => 'All hands at 5pm']);
        Queue::assertPushed(SendPushNotificationJob::class,
            fn ($j) => $j->storeId === $store->id && $j->body === 'All hands at 5pm');
    }

    /** SP9 #42: a non-manager cannot broadcast. */
    public function test_non_manager_cannot_broadcast(): void
    {
        Queue::fake();
        [$owner, $store] = $this->makeRestaurant();
        $waiter = $this->makeStaff($owner, $store, 'waiter');

        $this->actingAs($waiter, 'staff')->postJson('/staff-app/broadcast', ['body' => 'nope'])->assertStatus(403);
        Queue::assertNotPushed(SendPushNotificationJob::class);
    }

    /** SP11 #50: the shell flips to RTL + the right lang for an Arabic locale. */
    public function test_shell_is_rtl_for_arabic_locale(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');

        app()->setLocale('ar');
        $html = $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);

        app()->setLocale('en');
        $ltr = $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $ltr);
    }

    /** SP9: the shell serves the Comms view. */
    public function test_shell_renders_comms_view(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $html = $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->getContent();
        $this->assertStringContainsString('data-v="comms"', $html);
        $this->assertStringContainsString('/staff-app/messages', $html);
        $this->assertStringContainsString('function loadComms', $html);
    }

    public function test_kitchen_role_without_orders_permission_is_forbidden(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'kitchen'); // only 'kds'
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/status", ['status' => 'preparing'])
            ->assertStatus(403);
    }

    public function test_cannot_touch_another_restaurants_order(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        [, $otherPost] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store);
        $foreign = Order::create(['store_id' => $otherPost->id, 'status' => 'new', 'type' => 'takeaway']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$foreign->id}/status", ['status' => 'preparing'])
            ->assertStatus(404) /* P1-04 slice 5: the tenant scope hides a foreign store's order (was 403) */;
    }

    public function test_subscribe_stores_push_subscription_deduped(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store);

        $sub = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK', 'auth' => 'AK']];

        $this->actingAs($staff, 'staff')->postJson('/staff-app/push/subscribe', $sub)->assertOk();
        $this->actingAs($staff, 'staff')->postJson('/staff-app/push/subscribe', $sub)->assertOk(); // same endpoint

        $this->assertSame(1, PushSubscription::where('store_id', $store->id)->count());
    }

    public function test_guest_cannot_read_feed(): void
    {
        $this->getJson('/staff-app/orders')->assertStatus(401);
    }

    public function test_push_job_is_noop_without_vapid_config(): void
    {
        config(['webpush.public_key' => '', 'webpush.private_key' => '']);
        [$owner, $store] = $this->makeRestaurant();
        PushSubscription::create([
            'store_id' => $store->id, 'endpoint' => 'https://x/y', 'endpoint_hash' => hash('sha256', 'https://x/y'),
            'public_key' => 'p', 'auth_token' => 'a',
        ]);

        // Must not throw when unconfigured.
        new SendPushNotificationJob($store->id, 'T', 'B')->handle();
        $this->assertTrue(true);
    }

    public function test_shell_wires_the_notification_sound_engine(): void
    {
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store);

        $html = $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->getContent();
        $this->assertStringContainsString('notify-sounds.js', $html);     // engine loaded
        $this->assertStringContainsString('NotifySounds.init', $html);    // configured
        $this->assertStringContainsString("NotifySounds.play('order_new')", $html); // rings on new order
    }
}
