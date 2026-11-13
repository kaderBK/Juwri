<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Disputes\DisputeService;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Services\SplitBillService;
use Tests\TestCase;

/**
 * P1-05 slice 5a (characterisation): the public money behaviour of split-bill shares (check_payments), item
 * refund lines (order_refund_items) and payment disputes — amounts, attribute/JSON shapes, the paid/remaining
 * sums of a check and the split-bill cash counted in the POS drawer. Written against the decimal columns BEFORE
 * the move to `*_minor` and kept green after it: only the storage changes.
 */
class PaymentsMoneyCharacterisationTest extends TestCase
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

    private function order(Store $r, string $price, int $qty = 1): Order
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Item', 'price' => $price, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'variation' => 0]);

        return $order->fresh();
    }

    public function test_split_shares_read_as_store_precision_strings_and_sum_on_the_check(): void
    {
        $r = $this->store('USD');
        $order = $this->order($r, '10.00');

        $check = SplitBillService::createEvenSplit($order, 3);
        $amounts = $check->payments->pluck('amount')->all();
        $this->assertSame(['3.33', '3.33', '3.34'], $amounts, 'shares keep the store-precision string (old StoreMoney)');
        $this->assertSame('3.33', $check->payments->first()->toArray()['amount']);

        SplitBillService::markSharePaid($check->payments->first(), 'Ann', 'cash');
        $fresh = TableCheck::find($check->id);
        $this->assertSame(3.33, $fresh->paidAmount());
        $this->assertSame(6.67, $fresh->remaining());
        $this->assertSame('partial', $fresh->status);

        foreach ($fresh->payments()->where('status', 'pending')->get() as $share) {
            SplitBillService::markSharePaid($share, null, 'card');
        }
        $this->assertSame(10.0, TableCheck::find($check->id)->paidAmount());
        $this->assertSame(0.0, TableCheck::find($check->id)->remaining());
        $this->assertTrue((bool) $order->fresh()->is_paid);
    }

    public function test_cash_split_shares_count_in_the_expected_drawer_cash(): void
    {
        $r = $this->store('USD');
        $shift = PosService::openShift($r, null, null, 50);
        $order = $this->order($r, '20.00');

        $check = SplitBillService::createEvenSplit($order, 2);
        SplitBillService::markSharePaid($check->payments[0], null, 'cash');
        SplitBillService::markSharePaid($check->payments[1], null, 'card');

        // opening float 50 + the one cash share of 10 (the card share is not drawer cash).
        $this->assertEquals(60.0, PosService::zReport($shift->fresh())['expected_cash']);
    }

    public function test_three_decimal_store_keeps_fils_on_a_share(): void
    {
        $r = $this->store('OMR');
        $order = $this->order($r, '1.275');
        $check = TableCheck::create(['store_id' => $r->id, 'order_id' => $order->id, 'total' => '1.275', 'parts' => 1, 'status' => 'open']);
        $share = CheckPayment::create([
            'table_check_id' => $check->id, 'share_no' => 1, 'amount' => '1.275', 'method' => 'cash',
            'status' => 'pending', 'token' => Str::random(40),
        ]);

        $this->assertSame('1.275', CheckPayment::find($share->id)->amount);
        $this->assertSame('1.275', CheckPayment::find($share->id)->toArray()['amount']);
    }

    public function test_refund_item_lines_keep_their_amount_shape(): void
    {
        $r = $this->store('USD');
        $order = $this->order($r, '12.50', 2);
        $line = OrderItem::where('order_id', $order->id)->firstOrFail();

        $row = OrderRefundItem::create([
            'order_id' => $order->id, 'order_item_id' => $line->id, 'qty' => 1, 'amount' => 12.5, 'reason' => 'cold',
        ]);
        $fresh = OrderRefundItem::find($row->id);
        $this->assertSame('12.50', $fresh->amount);
        $this->assertSame('12.50', $fresh->toArray()['amount']);
        $this->assertSame(1, (int) OrderRefundItem::where('order_item_id', $line->id)->sum('qty'));

        $omr = $this->store('OMR');
        $omrOrder = $this->order($omr, '0.550', 2);
        $omrLine = OrderItem::where('order_id', $omrOrder->id)->firstOrFail();
        $row = OrderRefundItem::create(['order_id' => $omrOrder->id, 'order_item_id' => $omrLine->id, 'qty' => 1, 'amount' => '0.275']);
        $this->assertSame('0.275', OrderRefundItem::find($row->id)->amount);
    }

    public function test_stripe_dispute_amount_from_minor_units(): void
    {
        $r = $this->store('USD');
        $order = Order::create(['store_id' => $r->id, 'status' => 'completed', 'type' => 'takeaway']);
        Transaction::create([
            'user_id' => $r->user_id, 'product_id' => $order->id, 'amount' => 25, 'currency_code' => 'USD',
            'payment_id' => 'ch_CHAR1', 'transaction_gatway' => 'stripe',
        ]);

        $d = (new DisputeService)->upsertFromStripe(['type' => 'charge.dispute.created', 'data' => ['object' => [
            'id' => 'dp_char1', 'charge' => 'ch_CHAR1', 'amount' => 2599, 'currency' => 'usd', 'status' => 'needs_response',
            'reason' => 'fraudulent', 'created' => now()->timestamp,
        ]]]);

        $fresh = Dispute::find($d->id);
        $this->assertSame($r->id, (int) $fresh->store_id);
        $this->assertSame('25.99', $fresh->amount);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame('25.99', $fresh->toArray()['amount']);

        // A later event for the same dispute updates it in place.
        (new DisputeService)->upsertFromStripe(['type' => 'charge.dispute.updated', 'data' => ['object' => [
            'id' => 'dp_char1', 'charge' => 'ch_CHAR1', 'amount' => 1000, 'currency' => 'usd', 'status' => 'under_review',
        ]]]);
        $this->assertSame('10.00', Dispute::find($d->id)->amount);
        $this->assertSame(1, Dispute::count());
    }
}
