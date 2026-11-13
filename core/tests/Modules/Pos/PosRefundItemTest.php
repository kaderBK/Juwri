<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D1 — item-level refunds: per-line quantity caps, mixed multi-line refunds,
 * idempotent double-submit, audit rows, and the manager permission gate.
 */
class PosRefundItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function staff(Store $r, array $perms = ['pos' => true, 'pos_refund' => true], string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
            'permissions' => $perms,
        ]);
    }

    protected function item(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(3),
            'price' => $price, 'active' => 1, 'stock_qty' => 50, 'track_inventory' => 1,
        ]);
    }

    protected function paidOrder(Store $r, StaffMember $s, array $lines): Order
    {
        $res = $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'items' => $lines,
            ])->assertStatus(201);

        return PosService::settle(Order::findOrFail($res->json('id')), 'cash', 1000);
    }

    protected function lineId(int $orderId, int $menuId): int
    {
        return (int) OrderItem::where('order_id', $orderId)->where('item_id', $menuId)->firstOrFail()->id;
    }

    public function test_item_refund_records_adjustment_and_breakdown_rows(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $a = $this->item($r, 10);
        $order = $this->paidOrder($r, $s, [['item_id' => $a->id, 'quantity' => 2]]);
        $line = $this->lineId($order->id, $a->id);

        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [['order_item_id' => $line, 'qty' => 1, 'amount' => 10]],
                'reason' => 'one came back', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(201);

        $this->assertEqualsWithDelta(10.0, (float) OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))), 0.001);
        $this->assertSame(1, OrderRefundItem::where('order_id', $order->id)->where('order_item_id', $line)->count());
        $this->assertSame(1, (int) OrderRefundItem::where('order_item_id', $line)->sum('qty'));
    }

    public function test_per_line_quantity_cannot_exceed_what_remains(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $a = $this->item($r, 10);
        $order = $this->paidOrder($r, $s, [['item_id' => $a->id, 'quantity' => 2]]);
        $line = $this->lineId($order->id, $a->id);

        // Refund 1 of 2 first.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [['order_item_id' => $line, 'qty' => 1, 'amount' => 10]],
                'reason' => 'first', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(201);

        // Now 2 more would exceed the remaining 1 → rejected, nothing added.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [['order_item_id' => $line, 'qty' => 2, 'amount' => 20]],
                'reason' => 'too many', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(422);

        $this->assertSame(1, (int) OrderRefundItem::where('order_item_id', $line)->sum('qty'));
    }

    /**
     * HPMS §8-P1: two lines naming the SAME item in one request must not each pass the
     * per-line cap independently. Here money stays within the collected total, so only
     * the accumulated qty cap can reject the over-refund.
     */
    public function test_duplicate_lines_for_same_item_cannot_exceed_remaining(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $a = $this->item($r, 10);
        $order = $this->paidOrder($r, $s, [['item_id' => $a->id, 'quantity' => 2]]); // amount_due = 20
        $line = $this->lineId($order->id, $a->id);

        // Two lines of qty 2 (total qty 4 > 2 sold) but summing to 20 (== collected) →
        // the money cap passes; the qty accumulator must reject.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [
                    ['order_item_id' => $line, 'qty' => 2, 'amount' => 10],
                    ['order_item_id' => $line, 'qty' => 2, 'amount' => 10],
                ],
                'reason' => 'dup', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(422);

        $this->assertSame(0, (int) OrderRefundItem::where('order_item_id', $line)->sum('qty'));
    }

    public function test_mixed_lines_refund_in_one_call(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $a = $this->item($r, 10);
        $b = $this->item($r, 30);
        $order = $this->paidOrder($r, $s, [
            ['item_id' => $a->id, 'quantity' => 2],
            ['item_id' => $b->id, 'quantity' => 1],
        ]);
        $lineA = $this->lineId($order->id, $a->id);
        $lineB = $this->lineId($order->id, $b->id);

        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [
                    ['order_item_id' => $lineA, 'qty' => 1, 'amount' => 10],
                    ['order_item_id' => $lineB, 'qty' => 1, 'amount' => 30],
                ],
                'reason' => 'two items', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(201);

        $this->assertEqualsWithDelta(40.0, (float) OrderAdjustment::where('order_id', $order->id)->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))), 0.001);
        $this->assertSame(2, OrderRefundItem::where('order_id', $order->id)->count());
    }

    public function test_double_submit_with_same_key_is_absorbed(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $a = $this->item($r, 10);
        $order = $this->paidOrder($r, $s, [['item_id' => $a->id, 'quantity' => 2]]);
        $line = $this->lineId($order->id, $a->id);
        $key = (string) Str::uuid();

        $body = [
            'lines' => [['order_item_id' => $line, 'qty' => 1, 'amount' => 10]],
            'reason' => 'retry', 'idempotency_key' => $key,
        ];
        $first = $this->actingAs($s, 'staff')->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), $body)->assertStatus(201);
        $second = $this->actingAs($s, 'staff')->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), $body)->assertStatus(201);

        // Same adjustment id both times, and only one set of rows recorded.
        $this->assertSame($first->json('adjustment_id'), $second->json('adjustment_id'));
        $this->assertSame(1, OrderAdjustment::where('order_id', $order->id)->count());
        $this->assertSame(1, OrderRefundItem::where('order_id', $order->id)->count());
    }

    public function test_item_refund_total_capped_at_money_collected(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $a = $this->item($r, 10);
        $order = $this->paidOrder($r, $s, [['item_id' => $a->id, 'quantity' => 2]]); // $20 collected
        $line = $this->lineId($order->id, $a->id);

        // A line amount above the collected total is rejected.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [['order_item_id' => $line, 'qty' => 1, 'amount' => 25]],
                'reason' => 'over', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(422);

        $this->assertSame(0, OrderAdjustment::where('order_id', $order->id)->count());
    }

    public function test_item_refund_requires_pos_refund_permission(): void
    {
        $r = $this->storeFixture();
        // A plain cashier has `pos` (can ring/settle) but NOT `pos_refund`. One acting
        // user throughout — the register never swaps the authenticated staff mid-flow.
        $cashier = $this->staff($r, [], 'cashier');
        $this->assertTrue($cashier->hasPermission('pos'));
        $this->assertFalse($cashier->hasPermission('pos_refund'));

        $a = $this->item($r, 10);
        $order = $this->paidOrder($r, $cashier, [['item_id' => $a->id, 'quantity' => 1]]);
        $line = $this->lineId($order->id, $a->id);

        $this->actingAs($cashier, 'staff')
            ->postJson(route('pos.orders.refundItems', ['store' => $r->id, 'id' => $order->id]), [
                'lines' => [['order_item_id' => $line, 'qty' => 1, 'amount' => 10]],
                'reason' => 'no rights', 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(403);

        $this->assertSame(0, OrderRefundItem::where('order_id', $order->id)->count());
    }
}
