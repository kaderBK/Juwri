<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;
use Juwri\Core\Editions\Community\NoPlatformWallet;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Security review #12: an order paid 100% by stored value (wallet / gift card / platform wallet) has amount_due 0, and
 * the owner refund routes used to treat that as "no known amount" and skip the refund cap — any amount could be
 * refunded to store credit or recorded against the original payment. The cap now falls back to the order total.
 */
class OwnerRefundStoredValueCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: Order, 1: User} a 100.00 order fully covered by store credit (amount due 0) */
    private function walletPaidOrder(): array
    {
        return $this->storedValueOrder(1.0);
    }

    /** @return array{0: Order, 1: User} a 100.00 order, $walletShare of it paid by store credit and the rest due */
    private function storedValueOrder(float $walletShare): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111', 'title' => 'R', 'sub_title' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'X', 'price' => 100, 'active' => '1']);
        $customer = Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('56-')]);
        $order = Order::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1, 'payment_method' => 'wallet']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);
        $order = $order->fresh();
        $order->forceFill(['wallet_applied' => round((float) $order->total * $walletShare, 2)])->save();
        $order = $order->fresh();

        $this->assertGreaterThan(0, (float) $order->total);
        if ($walletShare >= 1.0) {
            $this->assertEqualsWithDelta(0.0, (float) $order->amount_due, 0.001);
        } else {
            $this->assertGreaterThan(0, (float) $order->amount_due);
        }

        return [$order, $owner];
    }

    /** @return array{0: Order, 1: User} an order with no priced lines: total 0, nothing was ever paid */
    private function zeroTotalOrder(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'z-'.rand(1000, 9999), 'color' => '#111', 'title' => 'Z', 'sub_title' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $customer = Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('57-')]);
        $order = Order::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1, 'payment_method' => 'cash'])->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $order->total, 0.001);

        return [$order, $owner];
    }

    /** Opus review SHOULD 2: on a mixed-tender order the wallet part can be refunded to store credit too. */
    public function test_store_credit_refund_of_a_mixed_tender_order_is_capped_at_the_total_not_the_amount_due(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $url = route('stores.refundOrderToCredit', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => (float) $order->total])->assertOk();
        $this->assertSame(1, $this->refundRows($order));

        // Nothing left: the cap is the total minus every refund so far.
        $this->actingAs($owner)->postJson($url, ['amount' => 1])->assertStatus(422);
        $this->assertSame(1, $this->refundRows($order));
    }

    /** Opus review SHOULD 2: a refund to the original tender stays capped at what the gateway/cash collected. */
    public function test_original_payment_refund_of_a_mixed_tender_order_stays_capped_at_the_amount_due(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $url = route('stores.refundOrderToOriginal', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => (float) $order->total])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));
    }

    /** Opus review SHOULD 3: nothing reached a gateway or the till, so there is no original payment to refund. */
    public function test_original_payment_refund_of_a_fully_wallet_paid_order_is_refused(): void
    {
        [$order, $owner] = $this->walletPaidOrder();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, $this->refundRows($order));
    }

    /** Opus review SHOULD 4: an order with no priced lines must not mint store credit. */
    public function test_store_credit_refund_of_a_zero_total_order_is_refused(): void
    {
        [$order, $owner] = $this->zeroTotalOrder();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 50])->assertStatus(422);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor(Customer::find($order->customer_id)), 0.001);
    }

    /** Opus review SHOULD 4: nor record a refund against the original payment. */
    public function test_original_payment_refund_of_a_zero_total_order_is_refused(): void
    {
        [$order, $owner] = $this->zeroTotalOrder();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 50])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));
    }

    /**
     * SEC-1119 fix-last: a fully wallet-paid order (amount due 0) used to take an uncapped path that passed no per-refund
     * ledger key, so the second legitimate partial refund collided on the per-order ref and credited nothing. Each refund
     * now has its own key (its refund row id); a double-submit of the same request still credits once.
     */
    public function test_two_partial_store_credit_refunds_of_a_wallet_paid_order_both_credit_and_a_replay_does_not(): void
    {
        [$order, $owner] = $this->walletPaidOrder();
        $customer = Customer::find($order->customer_id);
        $url = route('stores.refundOrderToCredit', $order->id);
        $start = CustomerWalletService::balanceFor($customer);

        $this->actingAs($owner)->postJson($url, ['amount' => 30, 'idempotency_key' => 'r-1'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 30, 'idempotency_key' => 'r-1'])->assertOk()->assertJson(['replayed' => true]);
        $this->assertEqualsWithDelta($start + 30, CustomerWalletService::balanceFor($customer->fresh()), 0.001);

        $this->actingAs($owner)->postJson($url, ['amount' => 20, 'idempotency_key' => 'r-2'])->assertOk();
        $this->assertEqualsWithDelta($start + 50, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
        $this->assertSame(2, $this->refundRows($order));
    }

    /**
     * SEC-1120 review SHOULD 1: a store-credit refund of the wallet part does not use up the card part. The card refund is
     * capped by what the card paid minus earlier card/cash refunds, and by what is left of the total.
     */
    public function test_card_refund_after_a_store_credit_refund_of_the_wallet_part_is_allowed(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $total = (float) $order->total;
        $due = (float) $order->amount_due;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $total - $due])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $due])->assertOk();
        $this->assertSame(2, $this->refundRows($order));

        // The whole total is refunded now: nothing more to either destination.
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertSame(2, $this->refundRows($order));
    }

    /** SEC-1120 review SHOULD 1: store credit that went beyond the wallet part still lowers what the card can get back. */
    public function test_card_refund_is_capped_by_what_is_left_of_the_total(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $total = (float) $order->total;
        $due = (float) $order->amount_due;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $total - $due + 10])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $due])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $due - 10])->assertOk();
        $this->assertSame(2, $this->refundRows($order));
    }

    /**
     * SEC-1120 review SHOULD 2: the refund caps use the stored order total, not the total worked out from the lines. A
     * paid order whose menu item was deleted (line worth 0 now) stays refundable, to store credit and to the card.
     */
    public function test_paid_order_whose_menu_item_was_deleted_stays_refundable(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.0);
        $order->snapshotTotals();
        $stored = (float) $order->fresh()->grand_total;
        $this->assertGreaterThan(0, $stored);

        Menu::whereKey($order->items()->value('item_id'))->first()->delete();
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->total, 0.001);

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 40])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $stored - 40])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertSame(2, $this->refundRows($order));
    }

    /** SEC-1120 review SHOULD 2: an order with a stored total > 0 stays refundable even with no lines left. */
    public function test_paid_order_with_a_stored_total_and_no_lines_stays_refundable(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.0);
        $order->snapshotTotals();
        $stored = (float) $order->fresh()->grand_total;
        OrderItem::where('order_id', $order->id)->delete();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $stored + 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $stored])->assertOk();
        $this->assertSame(1, $this->refundRows($order));
    }

    /**
     * SEC-1120 delta SHOULD 1: a grocery card-hold order captured for less than it authorised keeps its stored total
     * (picking does not re-snapshot it, and no refund row records the part released). The refund caps follow what was
     * captured, not what was authorised, so the owner cannot hand out store credit the customer never paid.
     */
    public function test_card_hold_order_captured_for_less_is_refundable_only_up_to_the_captured_amount(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.0);
        $order->snapshotTotals();
        $order = $order->fresh();
        $stored = (float) $order->grand_total;
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $captured = Money::round($stored * 0.6, money_decimals($currency));
        $order->forceFill([
            'hold_status' => 'captured',
            'hold_minor' => Money::minorFromMajor($stored, $currency),
            'captured_minor' => Money::minorFromMajor($captured, $currency),
        ])->save();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $captured + 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $captured + 1])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $captured])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertSame(1, $this->refundRows($order));
    }

    /** SEC-1120 delta SHOULD 1: a hold that was released (nothing captured) leaves nothing to refund. */
    public function test_card_hold_order_whose_hold_was_released_refunds_nothing(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.0);
        $order->snapshotTotals();
        $order->fresh()->forceFill(['hold_status' => 'voided', 'captured_minor' => 0])->save();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));
    }

    /**
     * SEC-1120 delta NIT 1: a register refund recorded as `other` can be a real payout (POS accepts method `other`), so
     * it uses up the card part. Only a refund to store credit leaves the card part alone.
     */
    public function test_register_refund_with_method_other_counts_against_the_card_refund_cap(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $total = (float) $order->total;
        $due = (float) $order->amount_due;
        OrderAdjustment::create([
            'order_id' => $order->id, 'staff_id' => null, 'pos_shift_id' => null, 'type' => 'refund',
            'amount' => $due, 'method' => 'other', 'reason' => 'Register refund',
        ]);

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $total - $due])->assertOk();
        $this->assertSame(2, $this->refundRows($order));
    }

    /**
     * money-last NIT 4: a full refund to the original payment leaves the stored-value tenders on the order (they stay
     * inside the store-credit cap, so they are never paid twice) and tells the owner what is left to give back.
     */
    public function test_a_full_refund_to_the_original_payment_tells_the_owner_the_tenders_left(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $tender = (float) $order->wallet_applied;
        $due = (float) $order->amount_due;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $due - 1])->assertOk()->assertJson(['tenders_left' => 0]);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertOk()
            ->assertJson(['tenders_left' => $tender]);
        $this->assertEqualsWithDelta($tender, (float) $order->fresh()->wallet_applied, 0.001, 'not given back by itself');

        // the tender part goes back once, by a refund to store credit, and the cancel then gives nothing more
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $tender])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $this->assertEqualsWithDelta($start + $tender, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
    }

    /** money-last 2: a full refund to store credit, then a cancel, never gives the store-credit tender back a second time. */
    public function test_cancel_after_a_full_refund_to_store_credit_does_not_return_the_tender_again(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $total = (float) $order->total;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $total])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();

        $this->assertEqualsWithDelta($start + $total, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'the total once, not the tender twice');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->wallet_applied, 0.001);
    }

    /** money-last 2: a partial refund to store credit covers that much of the tender; the cancel returns only the rest. */
    public function test_cancel_after_a_partial_refund_to_store_credit_returns_only_the_rest_of_the_tender(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $tender = (float) $order->wallet_applied;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 25])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk(); // a replay

        $this->assertEqualsWithDelta($start + $tender, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
        // the card part is still refundable to the card
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => (float) $order->amount_due])->assertOk();
    }

    /**
     * money-last 5: a failing platform-wallet step never breaks the owner cancel half way. The tenders step is all or
     * nothing, the failure is logged, the later steps still run, and cancelling again retries it.
     */
    public function test_a_failing_platform_wallet_step_is_logged_and_a_second_cancel_retries_it(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $this->app->instance(PlatformWalletTender::class, new class implements PlatformWalletTender
        {
            public function returnPart(Order $order, float $amount, string $key): bool
            {
                return false;
            }

            public function reverseOrder(Order $order, float $keep = 0.0): float
            {
                throw new \RuntimeException('wallet service down');
            }
        });

        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta($start, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'all tenders or none');
        $failed = ActivityLog::query()->where('action', 'order.cancel_step_failed')->get();
        $this->assertCount(1, $failed);
        $this->assertSame('tenders', $failed[0]->meta['step']);

        $this->app->instance(PlatformWalletTender::class, new NoPlatformWallet);
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $this->assertEqualsWithDelta($start + (float) $order->wallet_applied, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
    }

    /**
     * money-last2: a cancel gave the store-credit tender back, so the owner refunds are capped by what is left: the card
     * part. Before, both caps were the gross total, so the returned tender could be paid a second time (to store credit
     * or to the card / a manual provider).
     */
    public function test_after_a_cancel_returned_the_tender_owner_refunds_cannot_pay_it_again(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $tender = (float) $order->wallet_applied;
        $due = (float) $order->amount_due;

        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $this->assertEqualsWithDelta($start + $tender, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'the cancel gave the tender back');

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => $due + 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $due + 1])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $due])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertEqualsWithDelta($start + $tender + $due, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'the total once');
    }

    /**
     * money-last2: a full refund at the till gives the tenders back (restoreTendersIfFullyRefunded). A second till
     * refund, a refund to store credit or to the original payment then has nothing left: before, the till cap was the
     * amount due, which grew back to the gross total once the tenders were off the order.
     */
    public function test_after_a_full_till_refund_returned_the_tenders_no_refund_pays_them_again(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $tender = (float) $order->wallet_applied;
        $due = (float) $order->amount_due;

        PosService::refund($order, null, $due, 'cash', 'full refund');
        $this->assertEqualsWithDelta($start + $tender, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'the till gave the tender back');

        try {
            PosService::refund($order->fresh(), null, $tender, 'cash', 'again');
            $this->fail('a second till refund paid the returned tender out as cash');
        } catch (ValidationException) {
        }
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertSame(1, $this->refundRows($order));
        $this->assertSame(Money::minorFromMajor($tender, $order->minorMoneyCurrency()), (int) $order->fresh()->getAttribute('tenders_returned_minor'));
    }

    /**
     * ML-1120 review NIT 4: a re-cancel after more store credit was refunded never keeps the same credit refund twice
     * (`covered` less what was already kept) and never returns anything again.
     */
    public function test_a_re_cancel_after_a_kept_credit_refund_keeps_it_once_and_returns_nothing_more(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $customer = Customer::find($order->customer_id);
        $start = CustomerWalletService::balanceFor($customer);
        $currency = $order->minorMoneyCurrency();
        $total = (float) $order->total;
        $tender = (float) $order->wallet_applied;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 40])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $this->assertSame(Money::minorFromMajor(40, $currency), (int) $order->fresh()->getAttribute('tenders_kept_minor'));
        $this->assertSame(Money::minorFromMajor($tender - 40, $currency), (int) $order->fresh()->getAttribute('tenders_returned_minor'));

        $rest = Money::round($total - 40 - ($tender - 40), 2); // the card part
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => $rest])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();

        $this->assertSame(Money::minorFromMajor(40, $currency), (int) $order->fresh()->getAttribute('tenders_kept_minor'), 'kept once');
        $this->assertSame(Money::minorFromMajor($tender - 40, $currency), (int) $order->fresh()->getAttribute('tenders_returned_minor'), 'nothing returned again');
        $this->assertEqualsWithDelta($start + $total, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'the total once');
    }

    /** ML-1120 review NIT 6: a re-cancel keeps the first cancelled_at and is logged as a retry, not a status change. */
    public function test_a_re_cancel_keeps_the_first_cancelled_at(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();
        $first = $order->fresh()->cancelled_at;
        $this->assertNotNull($first);

        $this->travel(10)->minutes();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();

        $this->assertTrue($first->equalTo($order->fresh()->cancelled_at), 'the first cancel time is kept');
        $this->assertSame(1, ActivityLog::query()->where('action', 'order.status')->where('subject_id', $order->id)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'order.cancel_retried')->where('subject_id', $order->id)->count());
    }

    /**
     * ML-1120 review NIT 2: after a refund to store credit was kept against the tender and the rest returned by a
     * cancel, the till can still refund the card / cash part that is owed (the kept part was subtracted twice before:
     * as a tender and as the credit refund row), and never more.
     */
    public function test_after_a_kept_credit_refund_and_a_cancel_the_till_can_refund_the_cash_part_once(): void
    {
        [$order, $owner] = $this->storedValueOrder(0.6);
        $due = (float) $order->amount_due;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 40])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'cancelled'])->assertOk();

        PosService::refund($order->fresh(), null, $due, 'cash', 'the cash part');
        try {
            PosService::refund($order->fresh(), null, 1, 'cash', 'again');
            $this->fail('the till refunded more than was paid');
        } catch (ValidationException) {
        }
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 1])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 1])->assertStatus(422);
        $this->assertSame(2, $this->refundRows($order));
    }

    private function refundRows(Order $order): int
    {
        return OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->count();
    }

    public function test_store_credit_refund_above_the_total_of_a_wallet_paid_order_is_refused(): void
    {
        [$order, $owner] = $this->walletPaidOrder();
        $url = route('stores.refundOrderToCredit', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => 5000])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));

        // A refund within what was paid still works.
        $this->actingAs($owner)->postJson($url, ['amount' => (float) $order->total])->assertOk();
        $this->assertSame(1, $this->refundRows($order));
    }

    public function test_original_payment_refund_above_the_total_of_a_wallet_paid_order_is_refused(): void
    {
        [$order, $owner] = $this->walletPaidOrder();
        $url = route('stores.refundOrderToOriginal', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => 5000])->assertStatus(422);
        $this->assertSame(0, $this->refundRows($order));
    }
}
