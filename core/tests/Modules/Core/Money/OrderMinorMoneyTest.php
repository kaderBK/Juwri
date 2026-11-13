<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\CateringRequest;
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
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Tests\TestCase;

/**
 * P1-05 slice 3: order money is stored as `*_minor` integers in the order's own currency (`orders.currency_code`,
 * a fact of the sale); lines, modifiers and adjustments carry the order's currency; the old attribute names keep
 * their read/write/JSON shape and JSON adds the `*_minor` keys.
 */
class OrderMinorMoneyTest extends TestCase
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

    public function test_order_amounts_are_minor_units_of_the_stamped_currency_whatever_the_fill_order(): void
    {
        $jod = $this->store('JOD');
        // money before store_id: the currency is only known on save.
        $order = Order::create([
            'tip_amount' => '1.125', 'discount_amount' => 0.5, 'grand_total' => '12.345',
            'store_id' => $jod->id, 'type' => 'takeaway',
        ]);
        $row = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame('JOD', $row->currency_code);
        $this->assertSame(1125, (int) $row->tip_amount_minor);
        $this->assertSame(500, (int) $row->discount_amount_minor);
        $this->assertSame(12345, (int) $row->grand_total_minor);

        $fresh = Order::find($order->id);
        $this->assertSame('1.125', $fresh->tip_amount);
        $this->assertSame(0.5, $fresh->discount_amount);
        $this->assertSame(12.345, $fresh->grand_total);
        $this->assertSame('12.345', $fresh->money('grand_total')->major());

        $json = $fresh->toArray();
        $this->assertSame('1.125', $json['tip_amount']);
        $this->assertSame(1125, $json['tip_amount_minor']);
        $this->assertSame(12.345, $json['grand_total']);
        $this->assertSame(12345, $json['grand_total_minor']);
    }

    public function test_a_store_currency_change_never_reinterprets_an_order(): void
    {
        $r = $this->store('USD');
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'tip_amount' => 2.5]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'KWD');

        $order = Order::find($order->id);
        $order->update(['service_charge_amount' => 1]);
        $row = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame('USD', $row->currency_code, 'an order keeps the currency it was sold in');
        $this->assertSame(250, (int) $row->tip_amount_minor);
        $this->assertSame(100, (int) $row->service_charge_amount_minor);
    }

    public function test_lines_modifiers_and_adjustments_carry_the_orders_currency(): void
    {
        $r = $this->store('BHD');
        $dish = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => '1.000', 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'currency_code' => 'BHD']);
        $line = $order->items()->create(['item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '1.255']);
        $extra = OrderItemExtra::create(['order_item_id' => $line->id, 'price' => '0.105']);
        $adj = OrderAdjustment::create(['order_id' => $order->id, 'type' => 'comp', 'amount' => '0.5', 'reason' => 'x']);

        $this->assertSame(['BHD', 1255], [DB::table('order_items')->where('id', $line->id)->value('currency_code'), (int) DB::table('order_items')->where('id', $line->id)->value('unit_price_minor')]);
        $this->assertSame(['BHD', 105], [DB::table('order_item_extras')->where('id', $extra->id)->value('currency_code'), (int) DB::table('order_item_extras')->where('id', $extra->id)->value('price_minor')]);
        $this->assertSame(['BHD', 500], [DB::table('order_adjustments')->where('id', $adj->id)->value('currency_code'), (int) DB::table('order_adjustments')->where('id', $adj->id)->value('amount_minor')]);
        $this->assertSame('1.255', OrderItem::find($line->id)->unit_price);
        $this->assertSame('0.500', OrderAdjustment::find($adj->id)->amount);
    }

    public function test_sql_sums_add_each_row_at_its_own_exponent(): void
    {
        $usd = $this->store('USD');
        $kwd = $this->store('KWD');
        $jpy = $this->store('JPY');
        foreach ([[$usd, 10.25], [$kwd, 1.275], [$jpy, 500]] as [$store, $grand]) {
            Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'grand_total' => $grand, 'tip_amount' => 1]);
        }
        $this->assertSame([1025, 1275, 500], Order::orderBy('id')->pluck('grand_total_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsWithDelta(511.525, (float) Order::query()->sum(DB::raw(Order::minorMoneySql('grand_total'))), 0.000001);
        $this->assertEqualsWithDelta(3.0, (float) Order::query()->sum(DB::raw(Order::minorMoneySql('tip_amount'))), 0.000001);
        $this->assertEqualsWithDelta(511.525, Order::revenueSum(Order::query()), 0.000001);
        $by = Order::revenueSumByCurrency(Order::query());
        $this->assertEqualsWithDelta(1.275, $by['KWD'], 0.000001);
        $this->assertEqualsWithDelta(500.0, $by['JPY'], 0.000001);
    }

    public function test_cart_and_catering_minor_columns(): void
    {
        $r = $this->store('OMR');
        $cart = Cart::create(['store_id' => $r->id, 'status' => 'active', 'tip_amount' => 0.25]);
        $this->assertSame(['OMR', 250], [DB::table('carts')->where('id', $cart->id)->value('currency_code'), (int) DB::table('carts')->where('id', $cart->id)->value('tip_amount_minor')]);

        $req = CateringRequest::create([
            'store_id' => $r->id, 'name' => 'Office', 'event_at' => now()->addWeek(), 'headcount' => 10,
            'status' => 'requested', 'quote_total' => '45.125',
        ]);
        $row = DB::table('catering_requests')->where('id', $req->id)->first();
        $this->assertSame('OMR', $row->currency, 'the existing `currency` column is the row currency');
        $this->assertSame(45125, (int) $row->quote_total_minor);
        $this->assertSame('45.125', CateringRequest::find($req->id)->quote_total);
    }

    public function test_legacy_archive_order_rows_are_converted(): void
    {
        $row = LegacyMoneyColumns::upgradeRow('orders', ['grand_total' => '12.500', 'tip_amount' => null, 'currency_code' => 'KWD'], 'USD');
        $this->assertSame(['currency_code' => 'KWD', 'grand_total_minor' => 12500, 'tip_amount_minor' => null], array_intersect_key($row, array_flip(['currency_code', 'grand_total_minor', 'tip_amount_minor'])));
        $this->assertArrayNotHasKey('grand_total', $row);

        $line = LegacyMoneyColumns::upgradeRow('order_items', ['unit_price' => 7], 'USD');
        $this->assertSame(['unit_price_minor' => 700, 'currency_code' => 'USD'], $line);
    }
}
