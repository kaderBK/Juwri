<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Models\WaiterCall;
use Tests\TestCase;

/**
 * SYSTEMS-RUN W-P1/P2: waiter tableside — role gating, course fire→KDS, 86, seat
 * split, merge, calls.
 */
class WaiterModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        config(['settings.admin_allow_waiter_mode' => true]);
    }

    private function scenario(string $role = 'waiter'): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'W', 'role' => $role,
            'email' => 'staff'.Str::random(5).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 20, 'active' => 1]);
        $sold = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Gone', 'price' => 10, 'active' => 1, 'is_sold_out' => 1]);

        return [$owner, $store, $staff, $menu, $sold];
    }

    public function test_kitchen_role_is_forbidden(): void
    {
        [$owner, $store, $kitchen] = $this->scenario('kitchen');
        $this->actingAs($kitchen, 'staff')->getJson('/staff-app/waiter/floor')->assertStatus(403);
    }

    public function test_held_course_enters_kds_only_on_fire(): void
    {
        [$owner, $store, $staff, $menu, $sold] = $this->scenario();
        $order = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);

        // Add held course-2 item + a sold-out (86) item that must be skipped.
        $this->actingAs($staff, 'staff')->postJson("/staff-app/waiter/orders/{$order->id}/items", [
            'hold' => true,
            'items' => [
                ['item_id' => $menu->id, 'quantity' => 2, 'seat' => 1, 'course' => 2],
                ['item_id' => $sold->id, 'quantity' => 1, 'seat' => 1, 'course' => 2],
            ],
        ])->assertStatus(201)->assertJson(['added' => 1]);

        $this->assertSame('held', OrderItem::where('order_id', $order->id)->first()->status);
        // Not yet in KDS queue.
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->where('status', 'queued')->count());

        // Fire course 2 → enters queue.
        $this->actingAs($staff, 'staff')->postJson("/staff-app/waiter/orders/{$order->id}/fire", ['course' => 2])
            ->assertOk()->assertJson(['fired' => 1]);
        $this->assertSame('queued', OrderItem::where('order_id', $order->id)->first()->status);
    }

    public function test_split_by_seat_math(): void
    {
        [$owner, $store, $staff, $menu] = $this->scenario();
        $order = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'seat' => 1, 'variation' => 0, 'status' => 'queued']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'seat' => 2, 'variation' => 0, 'status' => 'queued']);

        $res = $this->actingAs($staff, 'staff')->getJson("/staff-app/waiter/orders/{$order->id}/split-seat")->assertOk();
        $seats = collect($res->json('seats'));
        $this->assertEqualsWithDelta(40.0, $seats->firstWhere('seat', 1)['subtotal'], 0.01);
        $this->assertEqualsWithDelta(20.0, $seats->firstWhere('seat', 2)['subtotal'], 0.01);
    }

    public function test_merge_moves_items_and_closes_source(): void
    {
        [$owner, $store, $staff, $menu] = $this->scenario();
        $a = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);
        $b = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '6']);
        OrderItem::create(['order_id' => $a->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        $this->actingAs($staff, 'staff')->postJson("/staff-app/waiter/orders/{$a->id}/merge", ['into_order_id' => $b->id])->assertOk();
        $this->assertSame(0, OrderItem::where('order_id', $a->id)->count());
        $this->assertSame(1, OrderItem::where('order_id', $b->id)->count());
        $this->assertSame('cancelled', $a->fresh()->status);
    }

    /**
     * Merging cancels the source and re-parents its items, so money on the source would be
     * stranded. A source carrying a discount/coupon/tender must be refused, not silently
     * merged (which would drop its discount from the combined bill).
     */
    public function test_merge_refused_when_source_carries_a_discount(): void
    {
        [$owner, $store, $staff, $menu] = $this->scenario();
        $a = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5', 'discount_amount' => 5.00]);
        $b = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '6']);
        OrderItem::create(['order_id' => $a->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$a->id}/merge", ['into_order_id' => $b->id])
            ->assertStatus(422)->assertJson(['ok' => false]);

        // Nothing moved, source not cancelled.
        $this->assertSame(1, OrderItem::where('order_id', $a->id)->count());
        $this->assertSame(0, OrderItem::where('order_id', $b->id)->count());
        $this->assertSame('pending', $a->fresh()->status);
    }

    public function test_merge_refused_when_source_is_settled(): void
    {
        [$owner, $store, $staff, $menu] = $this->scenario();
        $a = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed', 'table_number' => '5', 'is_paid' => 1]);
        $b = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '6']);
        OrderItem::create(['order_id' => $a->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$a->id}/merge", ['into_order_id' => $b->id])
            ->assertStatus(422);
        $this->assertSame(1, OrderItem::where('order_id', $a->id)->count());
    }

    public function test_merge_refused_when_a_split_is_in_progress(): void
    {
        [$owner, $store, $staff, $menu] = $this->scenario();
        $a = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);
        $b = Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '6']);
        OrderItem::create(['order_id' => $a->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);
        TableCheck::create(['store_id' => $store->id, 'order_id' => $a->id, 'total' => 10, 'parts' => 2, 'status' => 'open']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$a->id}/merge", ['into_order_id' => $b->id])
            ->assertStatus(422);
        $this->assertSame('pending', $a->fresh()->status);
    }

    public function test_waiter_call_flow(): void
    {
        [$owner, $store, $staff] = $this->scenario();
        // Diner raises a call via the existing public endpoint.
        $this->postJson("/{$store->id}/call-waiter", ['table' => 7, 'type' => 'call_waiter'])->assertOk();
        $call = WaiterCall::where('store_id', $store->id)->first();
        $this->assertNotNull($call);

        $this->actingAs($staff, 'staff')->getJson('/staff-app/waiter/calls')->assertOk()->assertJsonFragment(['table_no' => 7]);
        $this->actingAs($staff, 'staff')->postJson("/staff-app/waiter/calls/{$call->id}/ack")->assertOk();
        $this->assertTrue((bool) $call->fresh()->seen);
    }
}
