<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Modules\Payments\Services\Disputes\DisputeService;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Services\SplitBillService;
use Tests\TestCase;

/**
 * P1-05 slice 5a: check_payments / order_refund_items / disputes store `amount_minor` in the row currency — the
 * order's for shares and refund lines, the gateway's for disputes — and keep it after a store-currency change.
 */
class PaymentsMinorMoneyTest extends TestCase
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

    private function order(Store $r, string $price): Order
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Item', 'price' => $price, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);

        return $order->fresh();
    }

    public function test_shares_and_refund_lines_take_the_order_currency_and_keep_it(): void
    {
        $r = $this->store('KWD');
        $order = $this->order($r, '2.500');
        $check = TableCheck::create(['store_id' => $r->id, 'order_id' => $order->id, 'total' => '2.500', 'parts' => 2, 'status' => 'open']);
        $share = CheckPayment::create(['table_check_id' => $check->id, 'share_no' => 1, 'amount' => '1.250', 'status' => 'paid', 'token' => Str::random(40)]);
        CheckPayment::create(['table_check_id' => $check->id, 'share_no' => 2, 'amount' => '1.250', 'status' => 'pending', 'token' => Str::random(40)]);

        $row = DB::table('check_payments')->where('id', $share->id)->first();
        $this->assertSame('KWD', $row->currency_code);
        $this->assertSame(1250, (int) $row->amount_minor);
        $this->assertSame(1.25, TableCheck::find($check->id)->paidAmount());

        $line = OrderItem::where('order_id', $order->id)->firstOrFail();
        $refund = OrderRefundItem::create(['order_id' => $order->id, 'order_item_id' => $line->id, 'qty' => 1, 'amount' => '2.125']);
        $this->assertSame('KWD', DB::table('order_refund_items')->where('id', $refund->id)->value('currency_code'));
        $this->assertSame(2125, (int) DB::table('order_refund_items')->where('id', $refund->id)->value('amount_minor'));

        // The store moves to USD: stored facts keep their currency and amount.
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        $this->assertSame('1.250', CheckPayment::find($share->id)->amount);
        $this->assertSame('2.125', OrderRefundItem::find($refund->id)->amount);
        $this->assertSame('KWD', CheckPayment::find($share->id)->money('amount')->currency());
    }

    public function test_dispute_keeps_the_gateway_currency_precision(): void
    {
        $r = $this->store('USD');
        $d = (new DisputeService)->upsertFromStripe(['type' => 'charge.dispute.created', 'data' => ['object' => [
            'id' => 'dp_kwd', 'amount' => 1234, 'currency' => 'kwd', 'status' => 'needs_response',
        ]]]);
        $this->assertSame('1.234', Dispute::find($d->id)->amount, 'KWD fils are kept (the old cast rounded to the store decimals)');
        $this->assertSame(1234, (int) DB::table('disputes')->where('id', $d->id)->value('amount_minor'));

        $noCurrency = Dispute::create(['store_id' => $r->id, 'gateway' => 'x', 'external_id' => 'n1', 'amount' => 5]);
        $this->assertSame('USD', DB::table('disputes')->where('id', $noCurrency->id)->value('currency'));
        $this->assertSame(500, (int) DB::table('disputes')->where('id', $noCurrency->id)->value('amount_minor'));
    }

    /** 5d: an even split is done in the order currency's minor units (fils, whole yen), summing exactly. */
    public function test_even_split_uses_the_order_currency_minor_units(): void
    {
        $omr = $this->store('OMR');
        $check = SplitBillService::createEvenSplit($this->order($omr, '1.275'), 2);
        $this->assertSame(['0.637', '0.638'], $check->payments->pluck('amount')->all(), 'was 0.64 + 0.64 = 1.28 (total rounded to cents)');
        $this->assertSame(['OMR', 'OMR'], $check->payments->pluck('currency_code')->all());
        SplitBillService::markSharePaid($check->payments->first());
        $fresh = TableCheck::find($check->id);
        $this->assertSame(0.637, $fresh->paidAmount());
        $this->assertSame(0.638, $fresh->remaining());

        $jpy = $this->store('JPY');
        $check = SplitBillService::createEvenSplit($this->order($jpy, '1000'), 3);
        $this->assertSame([333, 333, 334], $check->payments->pluck('amount_minor')->all());
        $this->assertSame(1000, (int) $check->payments->sum('amount_minor'));

        $usd = $this->store('USD');
        $check = SplitBillService::createEvenSplit($this->order($usd, '0.10'), 3);
        $this->assertSame(['0.03', '0.03', '0.04'], $check->payments->pluck('amount')->all(), 'USD unchanged: cents, remainder last');
    }

    public function test_legacy_archive_rows_convert_in_their_currency(): void
    {
        $this->assertSame(['currency' => 'KWD', 'amount_minor' => 1234],
            LegacyMoneyColumns::upgradeRow('disputes', ['amount' => '1.234', 'currency' => 'KWD'], 'USD'));
        $this->assertSame(['amount_minor' => 1050, 'currency_code' => 'USD'],
            LegacyMoneyColumns::upgradeRow('check_payments', ['amount' => '10.50'], 'USD'));
        $this->assertSame(['amount_minor' => 275, 'currency_code' => 'OMR'],
            LegacyMoneyColumns::upgradeRow('order_refund_items', ['amount' => '0.275'], 'OMR'));
    }
}
