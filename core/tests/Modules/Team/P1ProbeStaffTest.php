<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\KdsService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Services\SplitBillService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * DISCOVERY probes (P1 scenario matrix, STF domain). Every test in here is written
 * to FAIL when the product behaves wrongly — a failure is the finding.
 */
class P1ProbeStaffTest extends TestCase
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

    private function staff(Store $r, string $role = 'cashier', array $extra = []): StaffMember
    {
        return StaffMember::create(array_merge([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function item(Store $r, float $price = 12, array $extra = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(array_merge(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1], $extra));
    }

    /**
     * PROBE 1 — a table that splits the bill and pays CASH at the register.
     *
     * Drawer truth: float 50 + 60 collected = 110 in the till. Closing the shift
     * with a counted 110 must report over_short 0.
     */
    public function test_probe_split_bill_cash_is_counted_in_the_drawer(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'cashier');
        $item = $this->item($r, 30);
        $shift = PosService::openShift($r, $staff, null, 50);

        $order = PosService::createOrder($r, $staff, [
            'type' => 'on-table', 'table_number' => '5',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);
        $this->assertEquals(60.0, round((float) $order->fresh()->total, 2), 'fixture sanity: order total');

        // Two diners each pay their half in cash, through the register's split-pay endpoint.
        $check = SplitBillService::createEvenSplit($order->fresh(), 2);
        foreach ($check->payments as $share) {
            $res = $this->actingAs($staff, 'staff')
                ->postJson(route('pos.orders.splitPay', $order->id), [
                    'share_id' => $share->id, 'method' => 'cash',
                ]);
            $res->assertOk();
        }
        $this->assertTrue((bool) $order->fresh()->is_paid, 'fixture sanity: order settled by the split');

        $z = PosService::closeShift($shift->fresh(), 110.0);

        $this->assertEquals(0.0, $z['over_short'], 'a correctly counted drawer must be neither over nor short');
        $this->assertEquals(110.0, $z['expected_cash'], 'expected drawer cash must include split-bill cash');
        $this->assertEquals(1, $z['orders'], 'the split-paid order belongs to the shift');
    }

    /**
     * PROBE 1b — the same hole, stated as the theft it enables: the cashier keeps
     * the £60 of split-bill cash and counts only the opening float back into the
     * drawer. A correct drawer must scream "£60 short"; if it balances, the money
     * is invisible.
     */
    public function test_probe_pocketed_split_cash_shows_up_as_a_short_drawer(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'cashier');
        $item = $this->item($r, 30);
        $shift = PosService::openShift($r, $staff, null, 50);

        $order = PosService::createOrder($r, $staff, [
            'type' => 'on-table', 'table_number' => '5',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);
        $check = SplitBillService::createEvenSplit($order->fresh(), 2);
        foreach ($check->payments as $share) {
            $this->actingAs($staff, 'staff')
                ->postJson(route('pos.orders.splitPay', $order->id), ['share_id' => $share->id, 'method' => 'cash'])
                ->assertOk();
        }
        $this->assertTrue((bool) $order->fresh()->is_paid, 'fixture sanity: £60 cash was collected');

        // Cashier hands back only the float; the £60 never reaches the till.
        $z = PosService::closeShift($shift->fresh(), 50.0);

        $this->assertEquals(-60.0, $z['over_short'], 'pocketing the split-bill cash must show as a 60 short drawer');
    }

    /**
     * PROBE 2 — the Z-report is the shift summary the manager reads and prints.
     * A shift with one cash and one card sale must report both.
     */
    public function test_probe_z_report_includes_card_sales(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'manager');
        $item = $this->item($r, 25);
        $shift = PosService::openShift($r, $staff, null, 0);

        $cash = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($cash, 'cash', 25.0);

        $card = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($card, 'card', null, 5.0); // 25 + 5 tip on the card

        $z = PosService::zReport($shift->fresh());

        $this->assertEquals(30.0, $z['sales_by_method']['card'] ?? 0.0, 'card line (25 + 5 tip)');
        $this->assertEquals(25.0, $z['sales_by_method']['cash'] ?? 0.0, 'cash line');
        $this->assertEquals(5.0, $z['tips'], 'card tip must appear in the shift tip total');
        $this->assertEquals(2, $z['orders'], 'both settled orders belong to the open shift');
    }

    /**
     * PROBE 3 — a cancelled (voided) order must stay cancelled. A bump arriving
     * from a KDS screen that rendered before the void must not resurrect it.
     */
    public function test_probe_kds_bump_does_not_resurrect_a_cancelled_order(): void
    {
        $r = $this->storeFixture();
        $manager = $this->staff($r, 'manager');
        $cook = $this->staff($r, 'kitchen');
        $item = $this->item($r, 10);

        $order = PosService::createOrder($r, $manager, ['type' => 'on-table', 'table_number' => '3', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::void($order->fresh(), $manager, 'walked out');
        $this->assertSame('cancelled', $order->fresh()->status, 'fixture sanity: void cancels the order');

        $line = $order->fresh()->items->first();
        $this->actingAs($cook, 'staff')
            ->postJson("/staff-app/kds/items/{$line->id}/bump")
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status, 'a cancelled order must not re-enter the kitchen flow');
    }

    /**
     * PROBE 3a — the voided order must also LEAVE the phone kitchen screen. If it
     * stays on the rail the cook has no way to know it was killed.
     */
    public function test_probe_voided_order_leaves_the_phone_kds_rail(): void
    {
        $r = $this->storeFixture();
        $manager = $this->staff($r, 'manager');
        $cook = $this->staff($r, 'kitchen');
        $item = $this->item($r, 10);

        $order = PosService::createOrder($r, $manager, ['type' => 'on-table', 'table_number' => '3', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::void($order->fresh(), $manager, 'walked out');
        $this->assertSame('cancelled', $order->fresh()->status, 'fixture sanity: void cancels the order');

        $tickets = $this->actingAs($cook, 'staff')->getJson('/staff-app/kds')->assertOk()->json('tickets');

        $this->assertCount(0, $tickets, 'a cancelled order must not stay on the kitchen rail');
    }

    /** PROBE 3b — same defect at the service layer, independent of the HTTP surface. */
    public function test_probe_kds_sync_leaves_cancelled_order_alone(): void
    {
        $r = $this->storeFixture();
        $manager = $this->staff($r, 'manager');
        $item = $this->item($r, 10);

        $order = PosService::createOrder($r, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $order->fresh()->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        KdsService::bumpItem($order->fresh()->items->first());

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /**
     * PROBE 4 — branch isolation on WRITE. A staff member pinned to branch A can
     * only read branch A's orders (the feed is branch-scoped); the write endpoint
     * must agree.
     */
    public function test_probe_staff_pinned_to_branch_a_cannot_mutate_branch_b_order(): void
    {
        $r = $this->storeFixture();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'active' => 1]);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B', 'active' => 1]);
        $staffA = $this->staff($r, 'manager', ['branch_id' => $a->id]);

        $orderB = Order::create(['store_id' => $r->id, 'branch_id' => $b->id, 'status' => 'new', 'type' => 'takeaway']);

        // Read side: branch B's order is invisible to branch A staff (documented behaviour).
        $feed = $this->actingAs($staffA, 'staff')->getJson('/staff-app/orders')->assertOk()->json('orders');
        $this->assertCount(0, $feed, 'fixture sanity: the feed already hides branch B');

        $this->actingAs($staffA, 'staff')
            ->postJson("/staff-app/orders/{$orderB->id}/status", ['status' => 'cancelled'])
            ->assertStatus(403);

        $this->assertSame('new', $orderB->fresh()->status, 'branch A staff must not cancel branch B orders');
    }

    /**
     * PROBE 4b — the same branch hole on the KDS surface. A cook pinned to branch A
     * must not see (nor bump) branch B's tickets.
     */
    public function test_probe_kds_is_scoped_to_the_cooks_own_branch(): void
    {
        $r = $this->storeFixture();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'active' => 1]);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B', 'active' => 1]);
        $cookA = $this->staff($r, 'kitchen', ['branch_id' => $a->id]);
        $staffB = $this->staff($r, 'cashier', ['branch_id' => $b->id]);
        $item = $this->item($r, 10);

        $orderB = PosService::createOrder($r, $staffB, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $this->assertSame((int) $b->id, (int) $orderB->fresh()->branch_id, 'fixture sanity: the order is branch B');

        $tickets = $this->actingAs($cookA, 'staff')->getJson('/staff-app/kds')->assertOk()->json('tickets');
        $this->assertCount(0, $tickets, 'branch A kitchen must not see branch B tickets');
    }
}
