<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\CateringRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Tests\TestCase;

/**
 * P1-05 slice 3 (characterisation): the public money behaviour of orders, order lines, adjustments, carts and
 * catering requests — totals, tax, discount, tip, service charge, tenders, the snapshot columns, the revenue
 * aggregates and the attribute/JSON shapes. Written against the decimal columns BEFORE the move to `*_minor`
 * and kept green after it: only the storage changes.
 */
class OrderMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $options */
    private function store(string $currency, array $options = []): Store
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        foreach ($options as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    public function test_usd_order_totals_tax_tip_discount_and_service_charge(): void
    {
        $r = $this->store('USD', ['restaurant_taxx_charge' => 10, 'restaurant_tax_type' => 'percentage']);
        $dish = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 12.5, 'active' => 1]);

        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $dish->id, 'quantity' => 2]],
            'tip_amount' => 3,
            'service_charge_amount' => 1.5,
            'discount_amount' => 4.25,
        ]);

        $fresh = Order::find($order->id);
        // P6-271: subtotal 25.00, tax 10% of (25.00 − 4.25) = 2.08, + tip 3 + service 1.5 − discount 4.25.
        $this->assertEqualsWithDelta(27.33, (float) $fresh->total, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $fresh->subtotal, 0.0001);
        $this->assertEqualsWithDelta(2.08, (float) $fresh->tax_total, 0.0001);
        $this->assertEqualsWithDelta(27.33, (float) $fresh->grand_total, 0.0001);
        $this->assertSame('3.00', $fresh->tip_amount, 'tip keeps the store-precision string (old StoreMoney)');
        $this->assertSame('1.50', $fresh->service_charge_amount);
        $this->assertEqualsWithDelta(4.25, (float) $fresh->discount_amount, 0.0001);
        $this->assertSame(27.33, $fresh->amount_due);

        $json = $fresh->toArray();
        $this->assertSame('3.00', $json['tip_amount']);
        $this->assertSame('1.50', $json['service_charge_amount']);
        $this->assertEqualsWithDelta(27.33, (float) $json['grand_total'], 0.0001);
    }

    public function test_three_decimal_store_keeps_its_fils_on_order_money(): void
    {
        $r = $this->store('OMR', ['restaurant_taxx_charge' => 5, 'restaurant_tax_type' => 'percentage']);
        $dish = Menu::create(['store_id' => $r->id, 'name' => 'Shuwa', 'price' => '1.275', 'active' => 1]);

        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $dish->id, 'quantity' => 3]],
            'tip_amount' => '0.275',
        ]);
        $fresh = Order::find($order->id);

        // 3 × 1.275 = 3.825; tax 5% = 0.19125 → 0.191 (3 decimals); + tip 0.275.
        $this->assertEqualsWithDelta(3.825, (float) $fresh->subtotal, 0.00001);
        $this->assertEqualsWithDelta(0.191, (float) $fresh->tax_total, 0.00001);
        $this->assertEqualsWithDelta(4.291, (float) $fresh->grand_total, 0.00001);
        $this->assertEqualsWithDelta(4.291, (float) $fresh->total, 0.00001);
        $this->assertSame('0.275', $fresh->tip_amount);
        $this->assertSame('OMR', $fresh->currency_code);
    }

    public function test_tenders_lower_the_amount_due_not_the_total(): void
    {
        $r = $this->store('JOD');
        $dish = Menu::create(['store_id' => $r->id, 'name' => 'Mansaf', 'price' => '10.500', 'active' => 1]);
        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $dish->id, 'quantity' => 1]],
        ]);
        $order->update(['wallet_applied' => '1.125', 'gift_card_applied' => 2, 'mk_wallet_applied' => '0.5']);

        $fresh = Order::find($order->id);
        $this->assertEqualsWithDelta(10.5, (float) $fresh->total, 0.00001);
        $this->assertSame('1.125', $fresh->wallet_applied);
        $this->assertSame('2.000', $fresh->gift_card_applied);
        $this->assertEqualsWithDelta(0.5, (float) $fresh->mk_wallet_applied, 0.00001);
        $this->assertEqualsWithDelta(6.875, $fresh->amount_due, 0.00001);
        $this->assertEqualsWithDelta(2.0, GiftCardService::redeemedTotalFor($r->id), 0.00001);
    }

    public function test_pos_cash_fields_and_channel_fee_keep_the_store_precision_string(): void
    {
        $r = $this->store('USD');
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1,
            'amount_tendered' => 50, 'change_due' => '7.255', 'channel_fee' => 2.1, 'donation_amount' => 0.4,
        ]);
        $fresh = Order::find($order->id);
        $this->assertSame('50.00', $fresh->amount_tendered);
        $this->assertSame('7.26', $fresh->change_due, 'rounded half-up to the store precision on write');
        $this->assertSame('2.10', $fresh->channel_fee);
        $this->assertSame('0.40', $fresh->donation_amount);
    }

    public function test_revenue_aggregates_sum_the_snapshot_per_currency(): void
    {
        $usd = $this->store('USD');
        $omr = $this->store('OMR');
        foreach ([[$usd, 10.25], [$usd, 4.5], [$omr, 1.275]] as [$store, $grand]) {
            Order::create([
                'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1,
                'grand_total' => $grand, 'subtotal' => $grand, 'tax_total' => 0,
            ]);
        }
        Order::create(['store_id' => $usd->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 0, 'grand_total' => 99]);
        Order::create(['store_id' => $usd->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1]);

        $query = Order::query();
        $this->assertEqualsWithDelta(16.025, Order::revenueSum($query), 0.00001, 'blind sum (existing contract)');
        $by = Order::revenueSumByCurrency($query);
        ksort($by);
        $this->assertEqualsWithDelta(1.275, $by['OMR'], 0.00001);
        $this->assertEqualsWithDelta(14.75, $by['USD'], 0.00001);
        $this->assertSame(1, Order::unsnapshottedCount(Order::query()), 'the paid order without a snapshot');
        $this->assertEqualsWithDelta(14.75, Order::revenueSum(Order::where('store_id', $usd->id)), 0.00001);
    }

    public function test_order_lines_extras_and_adjustments_keep_their_shapes(): void
    {
        $r = $this->store('KWD');
        $dish = Menu::create(['store_id' => $r->id, 'name' => 'Machboos', 'price' => '2.750', 'active' => 1]);
        $extra = MenuExtra::create(['store_id' => $r->id, 'menu_id' => $dish->id, 'title' => 'Sauce', 'price' => '0.125']);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending']);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $dish->id, 'quantity' => 2, 'unit_price' => '2.505']);
        OrderItemExtra::create(['order_item_id' => $line->id, 'extra_id' => $extra->id, 'price' => '0.150']);
        $adj = OrderAdjustment::create([
            'order_id' => $order->id, 'type' => 'refund', 'amount' => '1.005', 'method' => 'cash', 'reason' => 'Cold',
        ]);

        $line = OrderItem::find($line->id);
        $this->assertSame('2.505', $line->unit_price, 'unit price keeps the store-precision string');
        $this->assertEqualsWithDelta(0.15, (float) OrderItemExtra::where('order_item_id', $line->id)->first()->price, 0.00001);
        $this->assertSame('1.005', OrderAdjustment::find($adj->id)->amount);

        // (2.505 + 0.150) × 2 = 5.31
        $this->assertEqualsWithDelta(5.31, (float) Order::find($order->id)->total, 0.00001);
    }

    public function test_cart_tip_and_catering_quote(): void
    {
        $r = $this->store('USD');
        $cart = Cart::create(['store_id' => $r->id, 'status' => 'active', 'tip_amount' => 2]);
        $this->assertSame(2.0, Cart::find($cart->id)->tip_amount);
        $cart->update(['tip_amount' => null]);
        $this->assertNull(Cart::find($cart->id)->tip_amount);

        $req = CateringRequest::create([
            'store_id' => $r->id, 'name' => 'Office', 'event_at' => now()->addWeek(), 'headcount' => 10,
            'status' => 'requested', 'quote_total' => 450.5,
        ]);
        $this->assertSame('450.50', CateringRequest::find($req->id)->quote_total);
        $this->assertNull(CateringRequest::find($req->id)->deposit_amount);
    }
}
