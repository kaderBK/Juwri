<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Pos\Models\PosOfflineRecord;
use Tests\TestCase;

/**
 * Offline POS OP-6 (OFFLINE-POS-SPEC §4.3–4.6): conflicts between what a till did offline and what changed on the
 * server meanwhile. A paid sale is never re-priced (old price, tax change): it is booked as sold and flagged; stock
 * may go below zero (owner Q4) and is flagged; voids only for the till's own sale and never after a server-side change;
 * a late tender joins its (closed) shift and is flagged. The manager sees them all in the offline exceptions.
 */
class PosOfflineConflictTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_a_price_and_tax_change_while_offline_never_reprices_the_paid_sale(): void
    {
        $t = $this->make(10.5, '15');
        $t['item']->update(['price' => 12]);          // version 2 at the server
        StoreOption::updateStoreOption($t['store']->id, 'restaurant_taxx_charge', '5');
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 2]]); // sold with version 1
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => 2415, 'tendered_minor' => 2415]);

        $res = $this->replay($t, [$sale, $tender])->assertOk();
        $this->assertSame(['accepted', 'accepted'], array_column($res->json('results'), 'status'));
        $flags = $res->json('results.0.result.flags');
        $this->assertSame([['item_id' => $t['item']->id, 'sold_minor' => 1050, 'current_minor' => 1200]], $flags['priced_on_old_snapshot']);
        $this->assertSame(['sold' => ['mode' => 'percentage', 'rate' => '15'], 'current' => ['mode' => 'percentage', 'rate' => '5']], $flags['tax_changed']);

        $order = Order::query()->where('client_uuid', $sale['key'])->first();
        $money = $order->computeMoney();
        $this->assertSame([2100, 315, 2415], [Money::minorFromMajor((string) $money['subtotal'], 'SAR'), Money::minorFromMajor((string) $money['tax'], 'SAR'), Money::minorFromMajor((string) $money['grand'], 'SAR')],
            'the till money (old price, old 15 % tax), not the live 12.00 / 5 %');
        $this->assertSame(0, Money::minorFromMajor((string) $order->change_due, 'SAR'));
    }

    public function test_oversell_deleted_item_sold_out_and_staff_changes_are_flagged_not_refused(): void
    {
        $t = $this->make();
        $other = Menu::create(['store_id' => $t['store']->id, 'category_id' => $t['item']->category_id, 'name' => 'Gone', 'price' => 5, 'active' => 1]);
        $t['version'] = $this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('version');
        $other->update(['active' => 0]);
        $t['item']->update(['is_sold_out' => 1]);
        $t['cashier']->update(['active' => 0]);

        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 7], ['item_id' => $other->id, 'unit_minor' => 500, 'qty' => 1]]);
        $res = $this->replay($t, [$sale], $t['manager'])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $flags = $res->json('results.0.result.flags');
        $this->assertSame([$t['item']->id], $flags['oversell']);
        $this->assertSame([$other->id], $flags['item_gone']);
        $this->assertSame([$t['item']->id], $flags['sold_out_since']);
        $this->assertSame($t['cashier']->id, $flags['staff_changed']);
        $this->assertSame(-2, (int) $t['item']->fresh()->stock_qty, 'owner Q4: allowed below zero, then reconciled');
    }

    public function test_a_price_not_in_the_tills_snapshot_is_flagged(): void
    {
        $t = $this->make(10.5);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 900, 'qty' => 1]]);
        $res = $this->replay($t, [$sale])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->assertSame([['item_id' => $t['item']->id, 'sold_minor' => 900, 'snapshot_minor' => 1050]], $res->json('results.0.result.flags.price_not_in_snapshot'));
        $this->assertSame(900 + 135, Money::minorFromMajor((string) Order::query()->where('client_uuid', $sale['key'])->first()->computeMoney()['grand'], 'SAR'));
    }

    public function test_a_void_after_a_server_side_refund_or_from_another_till_is_a_conflict(): void
    {
        $t = $this->make();
        $a = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $b = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $this->replay($t, [$a, $b])->assertOk();
        $orderA = Order::query()->where('client_uuid', $a['key'])->first();
        OrderAdjustment::create(['order_id' => $orderA->id, 'type' => 'refund', 'amount' => 5, 'reason' => 'online', 'method' => 'cash']);

        $voidA = $this->rec($t, 'void', ['sale_key' => $a['key'], 'reason' => 'x'], $t['manager']->id);
        $this->replay($t, [$voidA])->assertOk()->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.reason', 'order_changed');
        $this->assertNotSame('cancelled', $orderA->fresh()->status);

        $voidB = $this->rec($t, 'void', ['sale_key' => $b['key'], 'reason' => 'x'], $t['manager']->id);
        $this->asStaff($t['cashier'])->withHeaders($this->till($t, 'till-0002-other'))->postJson(route('pos.offline.replay'), ['device_id' => 'till-0002-other', 'branch_id' => $t['branch']->id, 'records' => [$voidB]])
            ->assertOk()->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.reason', 'not_this_device');
    }

    public function test_a_line_void_keeps_the_till_tax_rule_for_the_remaining_lines(): void
    {
        $t = $this->make(10.5, '15');
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1], ['item_id' => $t['item']->id, 'unit_minor' => 333, 'qty' => 1]]);
        StoreOption::updateStoreOption($t['store']->id, 'restaurant_taxx_charge', '5');
        $void = $this->rec($t, 'void', ['sale_key' => $sale['key'], 'line' => 1, 'reason' => 'x'], $t['manager']->id);
        $this->replay($t, [$sale, $void])->assertOk();
        $money = Order::query()->where('client_uuid', $sale['key'])->first()->computeMoney();
        $this->assertSame([1050, 158, 1208], [Money::minorFromMajor((string) $money['subtotal'], 'SAR'), Money::minorFromMajor((string) $money['tax'], 'SAR'), Money::minorFromMajor((string) $money['grand'], 'SAR')]);
    }

    public function test_a_late_tender_joins_its_closed_shift_and_the_manager_sees_the_exceptions(): void
    {
        $t = $this->make();
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 0]);
        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 0], $t['manager']->id);
        $this->replay($t, [$open, $close])->assertOk();
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'card', 'amount_minor' => 1208, 'shift_key' => $open['key']]);
        $res = $this->replay($t, [$sale, $tender])->assertOk();
        $shiftId = PosOfflineRecord::query()->where('client_key', $open['key'])->first()->result['shift_id'];
        $this->assertSame($shiftId, $res->json('results.1.result.flags.late_for_closed_shift'));
        $this->assertSame($shiftId, (int) Order::query()->where('client_uuid', $sale['key'])->value('pos_shift_id'));

        $bad = $this->sale($t, [['item_id' => 999999, 'unit_minor' => 1, 'qty' => 1]]);
        $this->replay($t, [$bad])->assertOk();
        $this->asStaff($t['cashier'])->getJson(route('pos.offline.exceptions'))->assertForbidden();
        $list = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json('exceptions');
        $this->assertEqualsCanonicalizing([$tender['key'], $bad['key']], array_column($list, 'client_key'));

        $other = $this->make();
        $this->assertSame([], $this->asStaff($other['manager'])->getJson(route('pos.offline.exceptions'))->json('exceptions'), 'another store sees none of them');
    }
}
