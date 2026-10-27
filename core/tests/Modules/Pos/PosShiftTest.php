<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * POS P2: shift open/close + expected-cash/over-short math, and append-only
 * voids/refunds with manager-only gates.
 */
class PosShiftTest extends TestCase
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

    private function storeFixture(): Store
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

    private function staff(Store $r, string $role = 'cashier'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price = 12, array $extra = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(array_merge(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1], $extra));
    }

    /**
     * StaffLoginController logs staff into BOTH guards (web = their owner) and stamps
     * staff_session_id. context() must resolve the CASHIER on such sessions — before
     * this fix the owner web session won and a cashier ran the register as the owner
     * (no `pos` gate, no attribution).
     */
    public function test_staff_marked_session_resolves_staff_not_owner(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        // A staff row with pos explicitly denied: dual-guard session must 403.
        $denied = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'NoPos', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1, 'permissions' => ['pos' => false],
        ]);
        $this->actingAs($owner, 'web')->actingAs($denied, 'staff')
            ->withSession(['staff_session_id' => $denied->id])
            ->get(route('pos.index', ['store' => $r->id]))
            ->assertStatus(403);
    }

    public function test_staff_marked_session_attributes_orders_to_the_cashier(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $cashier = $this->staff($r);

        $res = $this->actingAs($owner, 'web')->actingAs($cashier, 'staff')
            ->withSession(['staff_session_id' => $cashier->id])
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
            ])->assertStatus(201);
        $this->assertSame($cashier->id, (int) Order::find($res->json('id'))->created_by_staff_id);
    }

    public function test_current_shift_endpoint_reports_open_shift(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);

        // No shift yet → shift: null.
        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.shift.current', ['store' => $r->id]))
            ->assertOk()->assertJsonPath('shift', null);

        $shift = PosService::openShift($r, $staff, null, 50);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.shift.current', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('shift.shift_id', $shift->id)
            ->assertJsonPath('shift.opening_float', 50);
    }

    public function test_open_shift_then_double_open_is_blocked(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        PosService::openShift($r, $staff, null, 50);
        $this->expectException(\RuntimeException::class);
        PosService::openShift($r, $staff, null, 50);
    }

    public function test_close_shift_computes_expected_and_over_short(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 12);
        $shift = PosService::openShift($r, $staff, null, 50);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'cash', 20.0); // stamped to the open shift

        $z = PosService::closeShift($shift->fresh(), 60.0); // count 60, expected 62 → short 2

        $this->assertEquals(62, $z['expected_cash']); // 50 float + 12 cash sale
        $this->assertEquals(-2, $z['over_short']);
        $this->assertSame('closed', $shift->fresh()->status);
    }

    public function test_expected_cash_subtracts_cash_refunds(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 20);
        $shift = PosService::openShift($r, $staff, null, 0);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'cash', 20.0);
        PosService::refund($order->fresh(), $staff, 5.0, 'cash', 'partial');

        $z = PosService::zReport($shift->fresh());
        $this->assertEquals(15, $z['expected_cash']); // 0 + 20 − 5
    }

    /**
     * A refund issued in a LATER shift than the order settled in must count against
     * the drawer it physically came out of (the current one), not the order's
     * already-closed settle shift. Pre-fix, expectedCash keyed refunds off
     * orders.pos_shift_id, so the current drawer read $5 over and the closed shift
     * silently absorbed a refund that never touched it.
     */
    public function test_a_cross_shift_cash_refund_hits_the_current_drawer(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 20);

        // Shift A: sell + settle $20 cash, then close balanced.
        $shiftA = PosService::openShift($r, $staff, null, 0);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'cash', 20.0);
        PosService::closeShift($shiftA->fresh(), 20.0); // expected 20, counted 20 → balanced

        // Shift B opens with $100 float; next day the manager refunds $5 cash on that order.
        $shiftB = PosService::openShift($r, $staff, null, 100);
        PosService::refund($order->fresh(), $staff, 5.0, 'cash', 'next-day return');

        // B's drawer is down $5 (100 − 5); the refund shows in B's Z-report, not A's.
        $zB = PosService::zReport($shiftB->fresh());
        $this->assertEquals(95, $zB['expected_cash'], 'the current drawer bears the refund');
        $this->assertEquals(5, $zB['refunds_total']);

        // A stayed balanced — its frozen close is untouched, and a recompute attributes
        // nothing to it (the refund was never in shift A).
        $this->assertEquals(0, PosService::zReport($shiftA->fresh())['refunds_total']);
        $this->assertEquals(0, (float) $shiftA->fresh()->over_short);
    }

    public function test_item_void_restores_stock_and_logs_adjustment(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 10, ['track_inventory' => 1, 'stock_qty' => 5]);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]]]);
        $this->assertSame(3, (int) $item->fresh()->stock_qty); // 5 − 2 on send

        $line = $order->items->first();
        $adj = PosService::void($order->fresh(), $staff, 'wrong item', $line->id);

        $this->assertSame(5, (int) $item->fresh()->stock_qty); // restored
        $this->assertDatabaseHas('order_adjustments', ['id' => $adj->id, 'type' => 'void']);
    }

    public function test_paid_order_cannot_be_voided_via_endpoint(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 10);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'cash', 10.0);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.void', $order->id), ['reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_refund_forbidden_for_cashier(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r, 10);
        $cashier = $this->staff($r, 'cashier'); // no pos_refund
        $order = PosService::createOrder($r, $cashier, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'card');

        $this->actingAs($cashier, 'staff')
            ->postJson(route('pos.orders.refund', $order->id), ['amount' => 5, 'method' => 'card', 'reason' => 'x'])
            ->assertStatus(403);
    }

    public function test_refund_allowed_for_manager(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r, 10);
        $manager = $this->staff($r, 'manager');
        $order = PosService::createOrder($r, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'card');

        $this->actingAs($manager, 'staff')
            ->postJson(route('pos.orders.refund', $order->id), ['amount' => 5, 'method' => 'card', 'reason' => 'x'])
            ->assertStatus(201);

        $this->assertDatabaseHas('order_adjustments', ['order_id' => $order->id, 'type' => 'refund']);
    }

    public function test_z_report_counts_voids_and_voids_total_in_the_open_shift(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 12);
        $shift = PosService::openShift($r, $staff, null, 50);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::void($order->fresh(), $staff, 'customer changed mind');

        $z = PosService::zReport($shift->fresh());
        $this->assertSame(1, $z['voids']);
        $this->assertEquals(12.0, $z['voids_total']);
    }

    public function test_void_without_open_shift_records_null_shift(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 10);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $adj = PosService::void($order->fresh(), $staff, 'no shift open');

        $this->assertNull($adj->fresh()->pos_shift_id);
    }

    public function test_void_in_another_shift_is_not_counted(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 10);

        $shiftA = PosService::openShift($r, $staff, null, 50);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::void($order->fresh(), $staff, 'voided in A');
        PosService::closeShift($shiftA->fresh(), 50.0);

        $shiftB = PosService::openShift($r, $staff, null, 50);

        $zB = PosService::zReport($shiftB->fresh());
        $this->assertSame(0, $zB['voids']);
        $this->assertEquals(0.0, $zB['voids_total']);

        $zA = PosService::zReport($shiftA->fresh());
        $this->assertSame(1, $zA['voids']);
        $this->assertEquals(10.0, $zA['voids_total']);
    }

    public function test_item_level_void_is_counted_in_z_report(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 10);
        $shift = PosService::openShift($r, $staff, null, 0);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $line = $order->items->first();
        PosService::void($order->fresh(), $staff, 'wrong item', $line->id);

        $z = PosService::zReport($shift->fresh());
        $this->assertSame(1, $z['voids']);
        $this->assertEquals(10.0, $z['voids_total']);
    }
}
