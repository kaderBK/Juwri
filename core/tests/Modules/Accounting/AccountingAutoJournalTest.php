<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * SYSTEMS-RUN A-P1: auto GL journals from settled sales/refunds.
 */
class AccountingAutoJournalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Store} taxed order: subtotal 100, VAT 15%, total 115 */
    private function taxedOrder(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '15');
        StoreOption::updateStoreOption($r->id, 'restaurant_tax_type', 'percentage');
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 50, 'active' => '1']);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'payment_method' => 'card']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'variation' => 0]);

        return [$order->fresh(), $r];
    }

    public function test_no_posting_when_feature_off(): void
    {
        config(['settings.admin_allow_accounting_gl' => false]);
        [$order] = $this->taxedOrder();
        $this->assertNull(OrderJournalPoster::postOrderPaid($order));
        $this->assertSame(0, GlJournal::count());
    }

    public function test_sales_journal_splits_revenue_and_vat(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order, $r] = $this->taxedOrder();

        $j = OrderJournalPoster::postOrderPaid($order);
        $this->assertNotNull($j);
        $this->assertEqualsWithDelta(115.0, $j->totalDebit(), 0.01);
        $this->assertEqualsWithDelta(115.0, $j->totalCredit(), 0.01);

        $j->load('lines.account');
        $vat = $j->lines->first(fn ($l) => $l->account->code === '2200');
        $rev = $j->lines->first(fn ($l) => $l->account->code === '4100');
        $cash = $j->lines->first(fn ($l) => $l->account->code === '1130'); // card → clearing
        $this->assertEqualsWithDelta(15.0, (float) $vat->credit, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $rev->credit, 0.01);
        $this->assertEqualsWithDelta(115.0, (float) $cash->debit, 0.01);
    }

    public function test_posting_is_idempotent(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order] = $this->taxedOrder();
        $a = OrderJournalPoster::postOrderPaid($order);
        $b = OrderJournalPoster::postOrderPaid($order);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, GlJournal::where('source_type', 'Order')->where('rule_key', 'order_paid')->count());
    }

    public function test_refund_reverses_into_gl(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order] = $this->taxedOrder();
        OrderJournalPoster::postOrderPaid($order);
        $j = OrderJournalPoster::postRefund($order, 115.0, 'x');
        $this->assertNotNull($j);
        $this->assertEqualsWithDelta(115.0, $j->totalDebit(), 0.01);
        $this->assertEqualsWithDelta(115.0, $j->totalCredit(), 0.01);
    }

    public function test_backfill_command_is_idempotent(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order, $r] = $this->taxedOrder();
        $this->artisan('accounting:backfill', ['--restaurant' => $r->id])->assertExitCode(0);
        $this->artisan('accounting:backfill', ['--restaurant' => $r->id])->assertExitCode(0);
        $this->assertSame(1, GlJournal::where('source_type', 'Order')->count());
    }

    /** Found by the e2eA accounting journey: a refund to the original payment never reached the books. */
    public function test_owner_refund_to_original_payment_posts_a_refund_journal_once(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        [$order, $r] = $this->taxedOrder();
        OrderJournalPoster::postOrderPaid($order);

        $this->actingAs(User::find($r->user_id))
            ->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 40])
            ->assertOk();

        $refunds = GlJournal::where('store_id', $r->id)->where('source_type', 'Order')->where('rule_key', 'like', 'refund%')->with('lines')->get();
        $this->assertCount(1, $refunds);
        $this->assertEqualsWithDelta(40.0, $refunds[0]->totalDebit(), 0.01);
        $this->assertEqualsWithDelta(40.0, $refunds[0]->totalCredit(), 0.01);
    }

    public function test_register_refund_posts_a_refund_journal_per_adjustment_and_replays_do_not_double_post(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order, $r] = $this->taxedOrder();
        OrderJournalPoster::postOrderPaid($order);

        PosService::refund($order, null, 30.0, 'cash', 'wrong item', 'k1');
        PosService::refund($order, null, 30.0, 'cash', 'wrong item', 'k1'); // replay of the same submit
        PosService::refund($order, null, 10.0, 'cash', 'late', 'k2');

        $refunds = GlJournal::where('store_id', $r->id)->where('rule_key', 'like', 'refund%')->with('lines')->get();
        $this->assertCount(2, $refunds);
        $this->assertEqualsWithDelta(40.0, $refunds->sum(fn ($j) => $j->totalDebit()), 0.01);
    }

    public function test_no_refund_journal_when_feature_off(): void
    {
        config(['settings.admin_allow_accounting_gl' => false]);
        [$order] = $this->taxedOrder();
        PosService::refund($order, null, 30.0, 'cash', 'x', 'k1');
        $this->assertSame(0, GlJournal::count());
    }

    /** Found by the e2eA accounting journey: a register (POS) sale was revenue the books never saw. */
    public function test_a_register_cash_sale_posts_the_sales_journal_once(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order, $r] = $this->taxedOrder();
        $order->forceFill(['is_paid' => 0, 'payment_method' => null])->save();

        $settled = PosService::settle($order->fresh(), 'cash', 200.0);
        PosService::settle($settled->fresh(), 'cash', 200.0); // a replayed settle

        $journals = GlJournal::where('store_id', $r->id)->where('rule_key', 'order_paid')->with('lines.account')->get();
        $this->assertCount(1, $journals);
        $this->assertEqualsWithDelta(115.0, $journals[0]->totalDebit(), 0.01);
        $cash = $journals[0]->lines->first(fn ($l) => $l->account->code === '1110'); // cash on hand
        $this->assertEqualsWithDelta(115.0, (float) $cash->debit, 0.01);
    }

    /** Coordinator review of lane A: the journal must be keyed per refund, never per order. */
    public function test_two_partial_refunds_to_the_original_payment_post_two_journals_and_a_replay_posts_none(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        [$order, $r] = $this->taxedOrder();
        OrderJournalPoster::postOrderPaid($order);
        $owner = User::find($r->user_id);
        $url = route('stores.refundOrderToOriginal', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => 40, 'idempotency_key' => 'k1'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 30, 'idempotency_key' => 'k2'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 40, 'idempotency_key' => 'k1'])->assertOk()->assertJson(['replayed' => true]);

        $refunds = GlJournal::where('store_id', $r->id)->where('rule_key', 'like', 'refund%')->with('lines')->get();
        $this->assertCount(2, $refunds);
        $this->assertEqualsWithDelta(70.0, $refunds->sum(fn ($j) => $j->totalDebit()), 0.01);

        // 115 collected, 70 refunded: 50 is more than is left, 45 is exactly what is left
        $this->actingAs($owner)->postJson($url, ['amount' => 50])->assertStatus(422);
        $this->actingAs($owner)->postJson($url, ['amount' => 45])->assertOk();
        $this->assertSame(3, GlJournal::where('store_id', $r->id)->where('rule_key', 'like', 'refund%')->count());
        $this->actingAs($owner)->postJson($url, ['amount' => 1])->assertStatus(422);
    }

    public function test_two_partial_refunds_to_store_credit_credit_twice_book_twice_and_a_replay_does_neither(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        [$order, $r] = $this->taxedOrder();
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order->forceFill(['customer_id' => $customer->id])->save();
        OrderJournalPoster::postOrderPaid($order->fresh());
        $owner = User::find($r->user_id);
        $url = route('stores.refundOrderToCredit', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => 20, 'idempotency_key' => 'c1'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 30, 'idempotency_key' => 'c2'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 20, 'idempotency_key' => 'c1'])->assertOk()->assertJson(['replayed' => true]);

        $this->assertEqualsWithDelta(50.0, CustomerWalletService::balanceFor($customer), 0.001);
        $refunds = GlJournal::where('store_id', $r->id)->where('rule_key', 'like', 'refund%')->with('lines')->get();
        $this->assertCount(2, $refunds);
        $this->assertEqualsWithDelta(50.0, $refunds->sum(fn ($j) => $j->totalDebit()), 0.01);
        $this->actingAs($owner)->postJson($url, ['amount' => 100])->assertStatus(422);
        $this->assertEqualsWithDelta(50.0, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_register_refunds_are_keyed_per_adjustment(): void
    {
        config(['settings.admin_allow_accounting_gl' => true]);
        [$order, $r] = $this->taxedOrder();
        OrderJournalPoster::postOrderPaid($order);
        PosService::refund($order, null, 10.0, 'cash', 'a', 'p1');
        PosService::refund($order, null, 10.0, 'cash', 'b', 'p2');
        $this->assertSame(2, GlJournal::where('store_id', $r->id)->where('rule_key', 'like', 'refund%')->count());
    }
}
