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
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * E2E-007 / STF-013: refunds happen exactly once (never above what was paid) and
 * voids reconcile quick stock — including the held-order case where stock was
 * never deducted in the first place.
 */
class PosRefundVoidGuardTest extends TestCase
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

    private function manager(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
            'permissions' => ['pos' => true, 'pos_refund' => true],
        ]);
    }

    private function item(Store $r, float $price = 10, int $stock = 20): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish',
            'price' => $price, 'active' => 1, 'stock_qty' => $stock, 'track_inventory' => 1,
        ]);
    }

    private function paidOrder(Store $r, StaffMember $staff, Menu $item, int $qty = 2): Order
    {
        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => $qty]],
            ])->assertStatus(201);
        $order = Order::findOrFail($res->json('id'));

        return PosService::settle($order, 'cash', 100);
    }

    public function test_refund_beyond_paid_total_is_rejected(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $order = $this->paidOrder($r, $m, $this->item($r));

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => (float) $order->total + 0.01, 'method' => 'cash', 'reason' => 'too much',
            ])->assertStatus(422);

        $this->assertSame(0, OrderAdjustment::where('order_id', $order->id)->count());
    }

    /**
     * A refund is money OUT — it must be bounded by the money actually COLLECTED, not
     * the gross order value. When part of the order was tendered by a gift card / store
     * credit (a tender, not a discount), only amount_due (= total − tenders) was ever
     * received as cash/card, and refund() never restores the consumed tender. Bounding
     * at gross `total` would pay that non-cash portion out as cash.
     */
    public function test_refund_is_capped_at_money_collected_not_gross_total(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $item = $this->item($r, 10, 20);

        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 2]],
            ])->assertStatus(201);
        $order = Order::findOrFail($res->json('id'));
        $this->assertEqualsWithDelta(20.0, (float) $order->total, 0.001); // 2 × $10, no tax

        // $8 of the $20 was paid by a gift card → only $12 real money was collected.
        $order->gift_card_applied = 8.0;
        $order->save();
        PosService::settle($order->fresh(), 'cash', 12);

        $order->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->total, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $order->amount_due, 0.001);

        // Refunding the GROSS total ($20) must be rejected — only $12 cash was taken
        // (pre-fix this passed, paying out the $8 gift-card portion as cash).
        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => 20.0, 'method' => 'cash', 'reason' => 'over-refund',
            ])->assertStatus(422);
        $this->assertSame(0, OrderAdjustment::where('order_id', $order->id)->count());

        // Refunding exactly what was collected ($12) is allowed.
        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => 12.0, 'method' => 'cash', 'reason' => 'full cash back',
            ])->assertStatus(201);

        // …and nothing beyond it.
        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => 0.01, 'method' => 'cash', 'reason' => 'extra',
            ])->assertStatus(422);
        $this->assertEqualsWithDelta(12.0, (float) OrderAdjustment::where('order_id', $order->id)->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))), 0.001);
    }

    public function test_second_full_refund_is_rejected_exactly_once_semantics(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $order = $this->paidOrder($r, $m, $this->item($r));
        $total = (float) $order->total;

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => $total, 'method' => 'cash', 'reason' => 'customer returned',
            ])->assertStatus(201);

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => $total, 'method' => 'cash', 'reason' => 'double fire',
            ])->assertStatus(422);

        $this->assertEqualsWithDelta($total, (float) OrderAdjustment::where('order_id', $order->id)->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))), 0.001);
    }

    public function test_partial_refunds_may_sum_to_total_but_never_beyond(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $order = $this->paidOrder($r, $m, $this->item($r)); // 2 × 10 (+ any tax) = total
        $total = (float) $order->total;
        $half = round($total / 2, 2);
        $rest = round($total - $half, 2);

        $refund = fn (float $amt) => $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), [
                'amount' => $amt, 'method' => 'cash', 'reason' => 'partial',
            ]);

        $refund($half)->assertStatus(201);
        $refund($rest)->assertStatus(201);
        $refund(0.01)->assertStatus(422);
    }

    public function test_refund_on_unpaid_order_is_rejected(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
            ])->assertStatus(201);

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $res->json('id')]), [
                'amount' => 1, 'method' => 'cash', 'reason' => 'not paid yet',
            ])->assertStatus(422);
    }

    /**
     * Offline/slow-network safety: the POS offline queue re-POSTs a mutation on
     * reconnect, and a lost response makes even an online refund retry. Amount-mode
     * refund used to record every attempt (only the item-mode sibling deduped), so a
     * replayed PARTIAL refund paid the customer twice and short-changed the drawer. With
     * the per-open idempotency key, a replay returns the first adjustment and records once.
     */
    public function test_amount_refund_replay_with_the_same_key_records_once(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $order = $this->paidOrder($r, $m, $this->item($r)); // total 20, $20 cash collected
        $payload = ['amount' => 5.0, 'method' => 'cash', 'reason' => 'one bad item', 'idempotency_key' => 'refund-open-1'];

        $first = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), $payload)->assertStatus(201);
        // The offline-queue flush (or a retried request whose response was lost) re-sends
        // the identical body — same key.
        $second = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.refund', ['store' => $r->id, 'id' => $order->id]), $payload)->assertStatus(201);

        $this->assertSame($first->json('adjustment_id'), $second->json('adjustment_id'), 'replay returns the first adjustment');
        $this->assertSame(1, OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->count(),
            'the partial refund is recorded once, not twice');
        $this->assertEqualsWithDelta(5.0, (float) OrderAdjustment::where('order_id', $order->id)
            ->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))), 0.001, 'only $5 left the drawer, not $10');
    }

    /** Back-compat: with NO key, two amount refunds are independent (pre-fix behaviour). */
    public function test_amount_refund_without_a_key_stays_independent(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $order = $this->paidOrder($r, $m, $this->item($r));

        PosService::refund($order->fresh(), $m, 5.0, 'cash', 'first');
        PosService::refund($order->fresh(), $m, 5.0, 'cash', 'second');

        $this->assertSame(2, OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->count(),
            'keyless refunds remain distinct — only a shared key dedupes');
    }

    /**
     * The POS offline queue re-POSTs a void on reconnect (and a lost response retries an
     * online one). A void'd order stays is_paid=0 — the endpoint's only guard — and is
     * still fetchable by id, so the replay reached void() and restored stock a second time
     * (Menu::restoreStock is a raw increment) and wrote a second void adjustment. It must
     * be idempotent: the recorded void comes back, stock and the Z-report stay correct.
     */
    public function test_full_void_replay_does_not_double_restore_stock_or_void(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $item = $this->item($r, 10, 20); // track_inventory, stock 20

        // Open tab (unpaid) for qty 2 → live order deducts stock to 18.
        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]],
            ])->assertStatus(201);
        $orderId = $res->json('id');
        $this->assertSame(18, (int) $item->fresh()->stock_qty, 'sale deducted 2');

        $a1 = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $orderId]), ['reason' => 'mistake'])
            ->assertStatus(201);
        $this->assertSame(20, (int) $item->fresh()->stock_qty, 'first void restored the 2');

        // Offline-queue flush / retried request re-sends the same void.
        $a2 = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $orderId]), ['reason' => 'mistake'])
            ->assertStatus(201);

        $this->assertSame(20, (int) $item->fresh()->stock_qty, 'stock not restored a second time');
        $this->assertSame($a1->json('adjustment_id'), $a2->json('adjustment_id'), 'replay returns the recorded void');
        $this->assertSame(1, OrderAdjustment::where('order_id', $orderId)->where('type', 'void')->count(),
            'one void adjustment, not two');
    }

    public function test_full_order_void_restores_stock_for_every_line(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $item = $this->item($r, 10, 20);

        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 3]],
            ])->assertStatus(201);
        $this->assertSame(17, (int) $item->fresh()->stock_qty); // deducted on ring

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $res->json('id')]), [
                'reason' => 'customer walked out',
            ])->assertStatus(201);

        $this->assertSame(20, (int) $item->fresh()->stock_qty); // fully restored
        $this->assertSame('cancelled', Order::find($res->json('id'))->status);
    }

    public function test_void_restore_clears_the_sold_out_flag_the_sale_set(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $item = $this->item($r, 10, 3);

        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 3]],
            ])->assertStatus(201);
        $item->refresh();
        $this->assertSame(0, (int) $item->stock_qty);
        $this->assertTrue((bool) $item->is_sold_out); // selling out flips the flag

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $res->json('id')]), [
                'reason' => 'rang wrong table',
            ])->assertStatus(201);

        $item->refresh();
        $this->assertSame(3, (int) $item->stock_qty);
        $this->assertFalse((bool) $item->is_sold_out); // restore un-flips it
    }

    /** A single-item void records only that line's value, not the whole order total. */
    public function test_partial_item_void_records_only_that_line_value(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $a = $this->item($r, 10, 20); // $10 line
        $b = $this->item($r, 30, 20); // $30 line

        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $a->id, 'quantity' => 1], ['item_id' => $b->id, 'quantity' => 1]],
            ])->assertStatus(201);
        $orderId = $res->json('id');
        $this->assertEqualsWithDelta(40.0, (float) Order::findOrFail($orderId)->total, 0.001);

        $lineA = OrderItem::where('order_id', $orderId)->where('item_id', $a->id)->firstOrFail();

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $orderId]), [
                'reason' => 'wrong item', 'order_item_id' => $lineA->id,
            ])->assertStatus(201);

        $adj = OrderAdjustment::where('order_id', $orderId)->where('type', 'void')->firstOrFail();
        // Voided line was $10 — not the full $40 order (the pre-fix behaviour).
        $this->assertEqualsWithDelta(10.0, (float) $adj->amount, 0.001);
        // The other line survives, so the order is not cancelled.
        $this->assertNotSame('cancelled', Order::find($orderId)->status);
    }

    /**
     * A split bill can hold PAID cash shares while the order is still is_paid=0
     * ('partial'). That collected cash only becomes visible to drawer reconciliation
     * once the whole order settles (payment_method='split'). Voiding a partial split
     * cancels the order and orphans the collected cash off the till — so void must be
     * refused while any share is paid (mirrors the paid-order refuse).
     */
    public function test_void_is_refused_while_a_split_share_is_already_paid(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $order = $this->paidOrder($r, $m, $this->item($r)); // rings, then we un-pay below

        // Reset to an open (unpaid) tab so we can split + part-pay it.
        $order->is_paid = 0;
        $order->payment_method = null;
        $order->save();

        $split = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.split', ['id' => $order->id, 'store' => $r->id]), ['parts' => 2])
            ->assertOk()->assertJsonCount(2, 'shares');
        $shares = $split->json('shares');

        // Pay share 1 in cash — order stays is_paid=0 ('partial'), $10 cash now collected.
        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.splitPay', ['id' => $order->id, 'store' => $r->id]), [
                'share_id' => $shares[0]['id'], 'method' => 'cash',
            ])->assertOk()->assertJsonPath('is_paid', false);

        // Voiding now would cancel the order and orphan that $10 — must be rejected.
        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $order->id]), [
                'reason' => 'skim the collected share',
            ])->assertStatus(422);

        $this->assertNotSame('cancelled', Order::find($order->id)->status);
        $this->assertSame(0, OrderAdjustment::where('order_id', $order->id)->where('type', 'void')->count());
    }

    public function test_void_of_a_held_order_does_not_inflate_stock(): void
    {
        $r = $this->storeFixture();
        $m = $this->manager($r);
        $item = $this->item($r, 10, 20);

        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'held' => true,
                'items' => [['item_id' => $item->id, 'quantity' => 3]],
            ])->assertStatus(201);
        $this->assertSame(20, (int) $item->fresh()->stock_qty); // held: never deducted

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.orders.void', ['store' => $r->id, 'id' => $res->json('id')]), [
                'reason' => 'parked in error',
            ])->assertStatus(201);

        $this->assertSame(20, (int) $item->fresh()->stock_qty); // unchanged — no phantom restock
    }
}
