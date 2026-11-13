<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\PosShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineRecord;
use Juwri\Modules\Pos\Pricing\TillCalculator;
use Juwri\Modules\Pos\Services\Offline\OfflineReplay;
use Tests\TestCase;

/**
 * Offline POS OP-5 (OFFLINE-POS-SPEC §3): the replay endpoint. Records apply exactly once in the till's order
 * (duplicate vs key_reused, a tender waiting for its sale, sequence gaps, clock skew), through the online code paths
 * (settle / void / shifts), and the order's money is the TillCalculator's money for the till's lines. Tenancy: one
 * store's till can never touch another store's orders or records.
 */
class PosOfflineReplayTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** P6-271: a sale queued by a till still on the old calculator (tax before the discount) is accepted and keeps it. */
    public function test_a_sale_priced_by_the_old_calculator_keeps_its_pre_discount_tax(): void
    {
        $t = $this->make(10.5);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 3, 'extras' => [['id' => 0, 'price_minor' => 200]]]],
            ['discount' => ['mode' => 'percent', 'rate' => '10'], 'tax_base' => 'gross']);
        $this->assertSame([563, 3938], [$sale['payload']['totals']['tax_minor'], $sale['payload']['totals']['total_minor']]);

        $this->replay($t, [$sale])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $order = Order::query()->where('client_uuid', $sale['key'])->firstOrFail();
        $this->assertTrue((bool) $order->tax_pre_discount);
        $this->assertSame(3938, Money::minorFromMajor((string) $order->computeMoney()['grand'], 'SAR'));
    }

    public function test_a_sale_and_its_cash_tender_book_the_till_calculator_money_through_settle(): void
    {
        $t = $this->make(10.5);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 3, 'extras' => [['id' => 0, 'price_minor' => 200]]]],
            ['discount' => ['mode' => 'percent', 'rate' => '10'], 'tip_minor' => 500, 'customer_name' => 'Sara']);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => $sale['payload']['totals']['total_minor'], 'tendered_minor' => 50000]);

        $res = $this->replay($t, [$tender, $sale])->assertOk(); // sent out of order: handled in seq order
        $this->assertSame(['accepted', 'accepted'], array_column($res->json('results'), 'status'));

        $order = Order::query()->where('client_uuid', $sale['key'])->firstOrFail();
        $money = $order->computeMoney();
        $want = $sale['payload']['totals']; // 3750 sub, 375 disc, P6-271 tax 15 % of 3375 = 506, 500 tip → 4381
        $this->assertSame([3750, 506, 4381], [$want['subtotal_minor'], $want['tax_minor'], $want['total_minor']]);
        $this->assertSame([$want['subtotal_minor'], $want['tax_minor'], $want['total_minor']],
            [Money::minorFromMajor((string) $money['subtotal'], 'SAR'), Money::minorFromMajor((string) $money['tax'], 'SAR'), Money::minorFromMajor((string) $money['grand'], 'SAR')]);
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame(['pos', 'cash', 'completed', 'Sara'], [$order->source, $order->payment_method, $order->status, $order->customer_name]);
        $this->assertSame(45619, Money::minorFromMajor((string) $order->change_due, 'SAR'));
        $this->assertSame(2, (int) $t['item']->fresh()->stock_qty, 'stock taken');
        $this->assertSame((int) $t['cashier']->id, (int) $order->created_by_staff_id);
    }

    public function test_a_resend_is_a_duplicate_and_a_reused_key_with_other_content_is_refused(): void
    {
        $t = $this->make();
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $this->replay($t, [$sale])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $again = $this->replay($t, [$sale])->assertOk();
        $this->assertSame('duplicate', $again->json('results.0.status'));
        $this->assertSame(1, Order::query()->where('store_id', $t['store']->id)->count());
        $this->assertSame(4, (int) $t['item']->fresh()->stock_qty, 'stock taken once');

        $other = $sale;
        $other['payload']['lines'][0]['qty'] = 2;
        $this->replay($t, [$other])->assertOk()->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.reason', 'key_reused');
        $this->assertSame(1, Order::query()->where('store_id', $t['store']->id)->count());
        $this->assertSame(1, (int) Order::query()->where('client_uuid', $sale['key'])->first()->items()->sum('quantity'), 'the first record stands');
    }

    public function test_a_tender_waits_for_its_sale_and_is_applied_once_it_arrives(): void
    {
        $t = $this->make();
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'card', 'amount_minor' => $sale['payload']['totals']['total_minor']]);

        $this->replay($t, [$tender])->assertOk()->assertJsonPath('results.0.status', 'waiting');
        $this->assertSame(0, PosOfflineRecord::query()->count(), 'nothing stored for a waiting record');
        $this->replay($t, [$sale, $tender])->assertOk()->assertJsonPath('results.1.status', 'accepted');
        $this->assertTrue((bool) Order::query()->where('client_uuid', $sale['key'])->value('is_paid'));
    }

    public function test_sequence_gaps_are_reported_and_filled_and_the_clock_skew_recorded(): void
    {
        $t = $this->make();
        $s1 = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $this->seq += 2; // seq 2 and 3 not sent yet
        $s4 = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $res = $this->replay($t, [$s1, $s4], null, ['device_clock' => now()->subMinutes(7)->toIso8601ZuluString()])->assertOk();
        $this->assertSame(4, $res->json('device.last_seq'));
        $this->assertSame([2, 3], $res->json('device.missing_seqs'));
        $this->assertEqualsWithDelta(420, $res->json('device.clock_skew_seconds'), 5, 'the server is 7 minutes ahead of the till');

        $s2 = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $s2['seq'] = 2;
        $this->replay($t, [$s2])->assertOk();
        $this->assertSame([3], PosOfflineDevice::query()->where('device_id', self::DEVICE)->first()->missing_seqs);
    }

    public function test_business_time_is_the_device_time_clamped(): void
    {
        $t = $this->make();
        $old = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $old['created_at'] = now()->subDays(3)->toIso8601ZuluString(); // before the snapshot it was priced with
        $future = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $future['created_at'] = now()->addDay()->toIso8601ZuluString();
        $ok = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $ok['created_at'] = now()->addMinutes(30)->toIso8601ZuluString(); // after the snapshot, before the replay
        $this->travel(2)->hours();
        $this->replay($t, [$old, $future, $ok])->assertOk();
        $at = fn ($s) => Order::query()->where('client_uuid', $s['key'])->first()->created_at;
        $this->assertTrue($at($old)->greaterThanOrEqualTo(now()->subHours(2)->subMinute()), 'not before the snapshot was built');
        $this->assertTrue($at($future)->lessThanOrEqualTo(now()), 'not after it reached the server');
        $this->assertEqualsWithDelta(now()->subMinutes(90)->getTimestamp(), $at($ok)->getTimestamp(), 5, 'otherwise the device time');
        $this->assertNotNull(PosOfflineRecord::query()->where('client_key', $old['key'])->value('device_created_at'), 'the original device time is kept');
    }

    public function test_void_of_an_offline_sale_restores_stock_once_and_needs_the_permission(): void
    {
        $t = $this->make();
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 2]]);
        $byCashier = $this->rec($t, 'void', ['sale_key' => $sale['key'], 'reason' => 'Wrong order']);
        $res = $this->replay($t, [$sale, $byCashier])->assertOk();
        $this->assertSame(['accepted', 'rejected'], array_column($res->json('results'), 'status'));
        $this->assertSame('not_permitted', $res->json('results.1.reason'), 'a cashier has no pos_refund');

        $byManager = $this->rec($t, 'void', ['sale_key' => $sale['key'], 'reason' => 'Wrong order'], $t['manager']->id);
        $this->replay($t, [$byManager])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->replay($t, [$byManager])->assertOk()->assertJsonPath('results.0.status', 'duplicate');
        $order = Order::query()->where('client_uuid', $sale['key'])->first();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(5, (int) $t['item']->fresh()->stock_qty, 'taken by the sale, given back once');
        $this->assertSame(1, OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'void')->count());
    }

    public function test_drawer_open_and_close_and_tenders_on_the_offline_shift(): void
    {
        $t = $this->make();
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 10000]);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => 1208, 'tendered_minor' => 1208, 'shift_key' => $open['key']]);
        $closeByCashier = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 11208]);
        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 11208], $t['manager']->id);
        $res = $this->replay($t, [$open, $sale, $tender, $closeByCashier, $close])->assertOk();
        $this->assertSame(['accepted', 'accepted', 'accepted', 'rejected', 'accepted'], array_column($res->json('results'), 'status'));
        $shiftId = $res->json('results.0.result.shift_id');
        $this->assertSame($shiftId, (int) Order::query()->where('client_uuid', $sale['key'])->value('pos_shift_id'));
        $shift = PosShift::query()->find($shiftId);
        $this->assertNotNull($shift->closed_at);
        $this->assertSame(0, Money::minorFromMajor((string) $shift->over_short, 'SAR'), 'float 100.00 + cash 12.08 counted exactly');
    }

    public function test_refusals_are_final_and_stored(): void
    {
        $t = $this->make();
        $good = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $wrongTotals = $good;
        $wrongTotals['key'] = self::key();
        $wrongTotals['seq'] = ++$this->seq;
        $wrongTotals['payload']['totals']['total_minor'] += 1;
        $unknownItem = $this->sale($t, [['item_id' => 999999, 'unit_minor' => 1050, 'qty' => 1]]);
        $badVersion = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $badVersion['snapshot_version'] = 99;
        $stranger = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]], [], null, 999999);
        $cardOffline = $this->rec($t, 'tender', ['sale_key' => $good['key'], 'method' => 'card_offline', 'amount_minor' => 1208]);
        $short = $this->rec($t, 'tender', ['sale_key' => $good['key'], 'method' => 'card', 'amount_minor' => 1000]);
        $badKey = ['type' => 'sale', 'key' => 'not-a-uuid', 'seq' => ++$this->seq, 'payload' => []];

        $res = $this->replay($t, [$good, $wrongTotals, $unknownItem, $badVersion, $stranger, $cardOffline, $short, $badKey])->assertOk();
        $this->assertSame([['accepted', null], ['conflict', 'pricing_mismatch'], ['rejected', 'unknown_item'], ['rejected', 'snapshot_unknown'], ['rejected', 'unknown_staff'],
            ['rejected', 'card_offline_unavailable'], ['rejected', 'amount_mismatch'], ['rejected', 'invalid']],
            array_map(fn ($r) => [$r['status'], $r['reason'] ?? null], $res->json('results')));
        $this->assertSame(1050 + 158, $res->json('results.1.result.server.total_minor'));
        $this->assertSame(1, Order::query()->where('store_id', $t['store']->id)->count(), 'nothing booked for a refused record');
        $this->assertSame(7, PosOfflineRecord::query()->count(), 'final answers stored (not the unusable key)');
        $this->replay($t, [$wrongTotals])->assertOk()->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.duplicate', true);
        $this->replay($t, [])->assertOk();
        $this->actingAs($t['cashier'], 'staff')->withHeaders($this->till($t, self::DEVICE))->postJson(route('pos.offline.replay'), ['device_id' => 'x', 'records' => []])->assertStatus(401); // not this till
        $this->actingAs($t['cashier'], 'staff')->withHeaders($this->till($t, self::DEVICE))->postJson(route('pos.offline.replay'), ['device_id' => self::DEVICE, 'records' => array_fill(0, OfflineReplay::MAX_RECORDS + 1, [])])->assertStatus(422);
    }

    public function test_a_till_of_one_store_can_never_touch_another_store(): void
    {
        $a = $this->make();
        $b = $this->make();
        $saleB = $this->sale($b, [['item_id' => $b['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $this->replay($b, [$saleB])->assertOk()->assertJsonPath('results.0.status', 'accepted');

        // A's cashier: B's item is not in A's snapshot; B's sale key is unknown to A (waits, never pays B's order);
        // B's branch is 404; B's key reused by A is A's own record, not B's.
        $itemOfB = $this->sale($a, [['item_id' => $b['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $payB = $this->rec($a, 'tender', ['sale_key' => $saleB['key'], 'method' => 'card', 'amount_minor' => 1208]);
        $voidB = $this->rec($a, 'void', ['sale_key' => $saleB['key'], 'reason' => 'x'], $a['manager']->id);
        $res = $this->replay($a, [$itemOfB, $payB, $voidB])->assertOk();
        $this->assertSame([['rejected', 'unknown_item'], ['waiting', 'parent_missing'], ['waiting', 'parent_missing']],
            array_map(fn ($r) => [$r['status'], $r['reason'] ?? null], $res->json('results')));
        $orderB = Order::query()->where('client_uuid', $saleB['key'])->first();
        $this->assertFalse((bool) $orderB->is_paid);
        $this->assertNotSame('cancelled', $orderB->status);
        $this->actingAs($a['cashier'], 'staff')->withHeaders($this->till($a, self::DEVICE))->postJson(route('pos.offline.replay'), ['device_id' => self::DEVICE, 'branch_id' => $b['branch']->id, 'records' => []])->assertNotFound();

        $sameKey = $this->sale($a, [['item_id' => $a['item']->id, 'unit_minor' => 1050, 'qty' => 1]], [], $saleB['key']);
        $this->replay($a, [$sameKey])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->assertSame(2, Order::query()->withoutGlobalScopes()->where('client_uuid', $saleB['key'])->count(), 'keys are per store');
        $this->assertSame([$a['store']->id], PosOfflineRecord::query()->withoutGlobalScopes()->where('client_key', $sameKey['key'])->where('store_id', $a['store']->id)->pluck('store_id')->all());
        $this->assertSame(2, PosOfflineDevice::query()->withoutGlobalScopes()->where('device_id', self::DEVICE)->count(), 'a device row per store');
        $this->assertSame((int) $a['store']->account_id, (int) PosOfflineRecord::query()->withoutGlobalScopes()->where('store_id', $a['store']->id)->value('account_id'));
    }

    public function test_an_offline_sale_stores_the_same_money_columns_as_the_same_sale_rung_up_online(): void
    {
        $t = $this->make(10.5);
        // online: the register's own path (store + cash settle)
        $online = $this->asStaff($t['cashier'])->postJson(route('pos.orders.store', ['store' => $t['store']->id]),
            ['type' => 'takeaway', 'client_uuid' => (string) Str::uuid7(), 'items' => [['item_id' => $t['item']->id, 'quantity' => 2]]])->assertStatus(201)->json('id');
        $this->asStaff($t['cashier'])->postJson(route('pos.orders.settle', ['id' => $online, 'store' => $t['store']->id]), ['method' => 'cash', 'tendered' => 50])->assertOk();
        // offline: the same sale replayed
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 2]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => $sale['payload']['totals']['total_minor'], 'tendered_minor' => 5000]);
        $this->replay($t, [$sale, $tender])->assertOk();

        $cols = ['subtotal_minor', 'tax_total_minor', 'grand_total_minor', 'discount_amount_minor', 'tip_amount_minor', 'captured_minor', 'amount_tendered_minor',
            'change_due_minor', 'is_paid', 'payment_method', 'currency_code', 'source', 'type']; // status differs on purpose: an offline sale was handed over already (completed)
        $a = (array) DB::table('orders')->where('id', $online)->first($cols);
        $b = (array) DB::table('orders')->where('client_uuid', $sale['key'])->first($cols);
        foreach ($cols as $c) {
            $this->assertEquals($a[$c], $b[$c], "orders.$c: online ".var_export($a[$c], true).' vs offline '.var_export($b[$c], true));
        }
        $this->assertNotNull($b['grand_total_minor']);
        $this->assertSame($sale['payload']['totals']['total_minor'], (int) $b['grand_total_minor']);
    }

    public function test_an_order_the_tills_lost_online_post_already_created_is_a_duplicate_not_an_endless_retry(): void
    {
        $t = $this->make(10.5);
        $key = self::key();
        // the till's first online POST created the order, but the answer never came back
        $this->asStaff($t['cashier'])->postJson(route('pos.orders.store', ['store' => $t['store']->id]),
            ['type' => 'takeaway', 'client_uuid' => $key, 'items' => [['item_id' => $t['item']->id, 'quantity' => 2]]])->assertStatus(201);
        $orders = Order::query()->count();
        // the queue sends the same sale again, under the same key, with its cash tender
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 2]], [], $key);
        $tender = $this->rec($t, 'tender', ['sale_key' => $key, 'method' => 'cash', 'amount_minor' => $sale['payload']['totals']['total_minor'], 'tendered_minor' => 5000]);
        $res = $this->replay($t, [$sale, $tender])->assertOk();
        $this->assertSame([['duplicate', 'order_exists'], ['accepted', null]], array_map(fn ($x) => [$x['status'], $x['reason'] ?? null], $res->json('results')));
        $this->assertSame($orders, Order::query()->count(), 'no second order');
        $order = Order::query()->where('client_uuid', $key)->sole();
        $this->assertTrue((bool) $order->is_paid, 'its tender settles the one order');
        // sent once more: still a duplicate, still one order
        $again = $this->replay($t, [$sale, $tender])->assertOk();
        $this->assertSame(['duplicate', 'duplicate'], array_column($again->json('results'), 'status'));
        $this->assertSame($orders, Order::query()->count());
    }

    public function test_an_existing_order_under_the_key_with_other_money_goes_to_a_manager(): void
    {
        $t = $this->make(10.5);
        $key = self::key();
        $this->asStaff($t['cashier'])->postJson(route('pos.orders.store', ['store' => $t['store']->id]),
            ['type' => 'takeaway', 'client_uuid' => $key, 'items' => [['item_id' => $t['item']->id, 'quantity' => 1]]])->assertStatus(201);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 3]], [], $key);
        $res = $this->replay($t, [$sale])->assertOk();
        $this->assertSame(['conflict', 'order_exists_mismatch'], [$res->json('results.0.status'), $res->json('results.0.reason')]);
        $this->assertSame(1, Order::query()->where('client_uuid', $key)->count());
        $this->assertSame('conflict', $this->replay($t, [$sale])->json('results.0.status'), 'final: not retried as an error');
    }

    public function test_options_carry_the_same_id_in_the_snapshot_and_the_live_menu_and_a_sale_picked_by_id_replays(): void
    {
        $t = $this->make(10.5);
        $g = MenuVariantOption::create(['store_id' => $t['store']->id, 'is_global' => false, 'menu_id' => $t['item']->id,
            'title' => 'Size', 'options' => ['Small', 'Large'], 'option_prices' => [0, 3], 'required' => false, 'min_select' => 0, 'max_select' => null, 'active' => 1]);
        $t['item']->attachedVariantOptions()->attach($g->id, ['active' => true, 'position' => 0]);
        $snap = $this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->assertOk();
        $t['version'] = $snap->json('version');
        $fromSnap = collect($snap->json('parts.catalogue.items'))->firstWhere('id', $t['item']->id)['choice_groups'][0]['options'];
        $live = collect($this->asStaff($t['cashier'])->getJson(route('pos.menu', ['store' => $t['store']->id]))->assertOk()->json('categories'))
            ->flatMap(fn ($c) => $c['items'])->firstWhere('id', $t['item']->id)['variant_groups'][0]['options'];
        $want = [[$g->id.':0', 'Small'], [$g->id.':1', 'Large']];
        $this->assertSame($want, array_map(fn ($o) => [$o['id'], $o['label']], $fromSnap));
        $this->assertSame($want, array_map(fn ($o) => [$o['id'], $o['label']], $live), 'the live menu and the snapshot share the option ids');

        // picked by id; the device's label text is not trusted (the snapshot's is stored)
        $line = ['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1, 'options' => [['id' => $g->id.':1', 'group_id' => $g->id, 'group' => 'x', 'option' => 'typo', 'price_minor' => 300]]];
        $sale = $this->sale($t, [$line]);
        $this->replay($t, [$sale])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $order = Order::query()->where('client_uuid', $sale['key'])->sole();
        $sel = $order->items()->sole()->options[0];
        $this->assertSame(['Size', 'Large', $g->id.':1'], [$sel['title'], $sel['selected'][0]['option'], $sel['selected'][0]['option_id']]);
        $this->assertSame($sale['payload']['totals']['total_minor'], (int) $order->grand_total_minor);

        $bad = $this->sale($t, [['options' => [['id' => $g->id.':9', 'group_id' => $g->id, 'group' => 'Size', 'option' => 'Huge', 'price_minor' => 0]]] + $line]);
        $this->assertSame(['rejected', 'unknown_option'], [$this->replay($t, [$bad])->json('results.0.status'), $this->replay($t, [$bad])->json('results.0.reason')]);
    }
}
