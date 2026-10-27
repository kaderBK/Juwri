<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Services\SplitBillService;
use Tests\TestCase;

/**
 * P1-05 slice 7a (characterisation): the public money behaviour of POS drawer shifts (opening float, expected /
 * counted cash, over/short — attribute strings, JSON and the Z-report numbers, handoff) and of a split-bill check's
 * total. Written against the decimal columns BEFORE the move to `*_minor` and kept green after it: only the storage
 * changes.
 */
class PosTablesMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function cashOrder(Store $r, PosShift $shift, string $price): Order
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Item', 'price' => $price, 'active' => 1]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new',
            'pos_shift_id' => $shift->id, 'payment_method' => 'cash', 'is_paid' => 1,
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);

        return $order->fresh();
    }

    public function test_shift_money_shapes_and_z_report(): void
    {
        $r = $this->store('USD');
        $shift = PosService::openShift($r, null, null, 50);
        $fresh = PosShift::find($shift->id);
        $this->assertSame('50.00', $fresh->opening_float, 'store-precision string (old StoreMoney)');
        $this->assertNull($fresh->expected_cash);
        $this->assertSame('50.00', $fresh->toArray()['opening_float']);

        $this->cashOrder($r, $shift, '12.50');
        $z = PosService::closeShift($fresh, 60.0);
        $this->assertSame(50.0, $z['opening_float']);
        $this->assertSame(62.5, $z['expected_cash']);
        $this->assertSame(60.0, $z['counted_cash']);
        $this->assertSame(-2.5, $z['over_short']);

        $closed = PosShift::find($shift->id);
        $this->assertSame('62.50', $closed->expected_cash);
        $this->assertSame('60.00', $closed->counted_cash);
        $this->assertSame('-2.50', $closed->over_short);
        $this->assertSame('-2.50', $closed->toArray()['over_short']);
        $this->assertEquals($z, PosService::zReport($closed));
    }

    public function test_handoff_carries_the_counted_cash_and_three_decimal_store_keeps_fils(): void
    {
        $r = $this->store('OMR');
        $shift = PosService::openShift($r, null, null, 10.125);
        $this->assertSame('10.125', PosShift::find($shift->id)->opening_float);

        $result = PosService::handoffShift($r, null, 9.875, null);
        $this->assertSame(10.125, $result['closed']['expected_cash']);
        $this->assertSame(-0.25, $result['closed']['over_short']);
        $this->assertSame('-0.250', PosShift::find($shift->id)->over_short);

        $next = PosShift::find($result['shift']->id);
        $this->assertSame('9.875', $next->opening_float);
        $this->assertSame((int) $shift->id, (int) $next->handoff_from_shift_id);
        $this->assertSame(9.875, PosService::zReport($next)['expected_cash']);
    }

    public function test_check_total_shape_and_remaining(): void
    {
        $r = $this->store('USD');
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Item', 'price' => '20.00', 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);

        $check = SplitBillService::createEvenSplit($order->fresh(), 4);
        $fresh = TableCheck::find($check->id);
        $this->assertSame('20.00', $fresh->total);
        $this->assertSame('20.00', $fresh->toArray()['total']);
        $this->assertSame(20.0, $fresh->remaining());

        SplitBillService::markSharePaid($fresh->payments->first(), null, 'cash');
        $this->assertSame(15.0, TableCheck::find($check->id)->remaining());

        $omr = $this->store('OMR');
        $o2 = Order::create(['store_id' => $omr->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new']);
        $c2 = TableCheck::create(['store_id' => $omr->id, 'order_id' => $o2->id, 'total' => '1.275', 'parts' => 1, 'status' => 'open']);
        $this->assertSame('1.275', TableCheck::find($c2->id)->total);
        $this->assertSame(1.275, TableCheck::find($c2->id)->remaining());
        $this->assertSame('0.00', TableCheck::create(['store_id' => $r->id, 'order_id' => $order->id, 'parts' => 1])->fresh()->total, 'column default');
    }
}
