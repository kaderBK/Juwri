<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\OrderCancellation;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-338 / J50 — when an order paid partly with a gift card is cancelled/voided and the
 * card is no longer spendable (expired or owner-voided), value is NOT put back on the
 * card. Instead, the redeemed amount is paid out via the gateway when possible (the
 * card's purchase transaction), then store credit, then a logged "pay by hand" cue.
 *
 *   • Active live card  → card is re-credited on cancel (regression).
 *   • Expired card      → gateway refund of the purchase, card balance unchanged.
 *   • Voided card       → provider refused → customer's store credit.
 *
 * Each route logs activity 'giftcard.<route>' and posts the merchant→platform clearing
 * leg with the fixed ref `gc:refund:order:{order}:card:{card}:out`. Idempotent: a
 * double cancel refunds once.
 */
class GiftCardUnspendableRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
        // Never let a delivery outbox / mailer reach the network from these tests.
        Mail::fake();
    }

    /** @return array{owner: User, r: Store, menu: Menu} */
    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /**
     * Fund a live card with $value via 'stripe', then stamp the purchase transaction
     * as settled (status = success) so refundUnspendable() routes it through the
     * provider branch (its viaProvider check requires STATUS_SUCCESS/CONSUMED).
     */
    private function card(Store $r, float $value, string $gateway = 'stripe'): GiftCard
    {
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, $value, $gateway);
        GiftCardService::activate($card, $txn);
        // The funding leg is posted, but the gateway-side settle is owned by the
        // webhook in production — synthesize it here so the test exercises the
        // "settled via a real provider" path that refundUnspendable expects.
        Transaction::query()->whereKey($txn->id)->update(['status' => Transaction::STATUS_SUCCESS]);

        return $card->fresh();
    }

    private function order(User $owner, Store $r, Menu $menu, int $qty, ?string $code = null): Order
    {
        return OrderApiService::create($owner->id, $r->id, [
            'type' => 'takeaway', 'source' => 'api',
            'gift_card_code' => $code,
            'items' => [['item_id' => $menu->id, 'quantity' => $qty]],
        ]);
    }

    /**
     * Regression — an ACTIVE live card still has its balance returned when the order
     * is cancelled. The unspendable branch never fires, so the gateway is not called.
     */
    public function test_active_live_card_is_recredited_and_gateway_is_never_called(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);

        $order = $this->order($o, $r, $m, 2, $card->code); // total $20, card pays it all
        $order->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);

        // The card is still active and not expired → reverseRedemption re-credits the
        // balance via the merchant→card ledger leg and never touches refundUnspendable.
        $this->mock(Gateways::class, function ($mock): void {
            $mock->shouldReceive('refundStorePayment')->never();
        });

        $order->status = 'cancelled';
        $order->save();
        OrderCancellation::reverse($order->fresh());

        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'card fully re-credited on cancel');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001, 'tender cleared');

        // No payout route fires for a live cancel — only the merchant→card leg posted.
        $this->assertSame(0, ActivityLog::where('action', 'like', 'giftcard.refunded_%')->count());
        $this->assertSame(0, ActivityLog::where('action', 'giftcard.refund_due')->count());
        $this->assertFalse(
            LedgerEntry::query()->where('ref', 'like', 'gc:refund:order:'.$order->id.':card:'.$card->id.':out:from')->exists(),
            'no merchant→clearing out leg for a live (active) card cancel'
        );
    }

    /**
     * P6-338 / J50 — an EXPIRED card never gets value back on the card. The gateway
     * is called once with the redeemed amount; on `automatic => true` the route is
     * 'refunded_to_payment' and the merchant→clearing out leg is posted.
     */
    public function test_expired_card_refunds_via_gateway_and_does_not_recredit_the_card(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);

        $order = $this->order($o, $r, $m, 2, $card->code); // total $20
        $order->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);

        // Make the card unspendable: past its expiry, status still 'active'.
        $card->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertTrue($card->fresh()->isExpired());

        // Provider refunds automatically: route → 'refunded_to_payment'.
        $this->mock(Gateways::class, function ($mock): void {
            $mock->shouldReceive('refundStorePayment')->once()
                ->andReturn(['automatic' => true, 'reference' => 're_abc', 'message' => null]);
        });

        $order->status = 'cancelled';
        $order->save();
        OrderCancellation::reverse($order->fresh());

        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001, 'expired card stays drained, no value returned');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001, 'tender zeroed under lock');
        $this->assertSame('cancelled', $order->fresh()->status);

        // Activity row stamped by refundUnspendable on the refunded_to_payment route.
        $this->assertTrue(
            ActivityLog::where('action', 'giftcard.refunded_to_payment')
                ->where('subject_id', $order->id)->exists(),
            'giftcard.refunded_to_payment activity was logged'
        );

        // The merchant → platform clearing leg for the payout is posted once.
        $this->assertTrue(
            LedgerEntry::query()->where('ref', 'gc:refund:order:'.$order->id.':card:'.$card->id.':out:from')->exists(),
            'merchant → platform clearing leg posted under the fixed :out ref'
        );
    }

    /**
     * Idempotent payout: a double cancel calls the gateway exactly once. The second
     * reverseRedemption zeroes nothing (gift_card_applied is already 0 under lock),
     * so no second payout is queued and the afterCommit payout runs once.
     */
    public function test_double_cancel_calls_gateway_exactly_once(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);

        $order = $this->order($o, $r, $m, 2, $card->code); // $20 redeemed
        $card->forceFill(['expires_at' => now()->subDay()])->save(); // unspendable

        $this->mock(Gateways::class, function ($mock): void {
            // The provider call must happen once even though reverseRedemption is called twice.
            $mock->shouldReceive('refundStorePayment')->once()
                ->andReturn(['automatic' => true, 'reference' => 're_once', 'message' => null]);
        });

        $order->status = 'cancelled';
        $order->save();
        OrderCancellation::reverse($order->fresh());
        // Repeat: order.gift_card_applied is already 0 → reverseRedemption returns 0.0
        // and queues NO further payout, so refundStorePayment is not called again.
        OrderCancellation::reverse($order->fresh());

        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001, 'tender stays zeroed');
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001, 'expired card stays drained');
        // One :out leg, one refund activity row — never twice.
        $this->assertSame(
            1,
            LedgerEntry::query()->where('ref', 'gc:refund:order:'.$order->id.':card:'.$card->id.':out:from')->count(),
            'merchant → clearing leg posted exactly once across both cancels'
        );
        $this->assertSame(
            1,
            ActivityLog::where('action', 'giftcard.refunded_to_payment')->where('subject_id', $order->id)->count(),
            'refunded_to_payment activity logged exactly once'
        );
    }

    /**
     * P6-338 / J50 — a VOIDED card (status = 'disabled') can't be re-credited. The
     * provider REFUSES (e.g. refund window passed) so we fall through to the
     * order's customer store-credit wallet. The redeemed amount lands in the
     * customer's balance in the card's currency.
     */
    public function test_disabled_card_with_provider_refusal_credits_the_customer_wallet(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 20);

        $order = $this->order($o, $r, $m, 2, $card->code); // $20 redeemed, card drained
        $order->refresh();
        $this->assertEqualsWithDelta(0.0, $card->fresh()->balance(), 0.001, 'card drained by the tender');

        // The order needs a customer for the store-credit branch — the diner who
        // paid with the gift card and now gets the refund as wallet credit.
        $customer = Customer::create(['name' => 'Jane', 'store_id' => $r->id, 'phone' => '555-'.random_int(1000, 9999)]);
        $order->customer_id = $customer->id;
        $order->save();

        // The owner voids the card. Balance is already 0 so no ledger post, status flips to 'disabled'.
        GiftCardService::void($card->fresh());
        $this->assertSame('disabled', $card->fresh()->status);

        // Provider says "no" (refund window passed) → fall through to store credit.
        $this->mock(Gateways::class, function ($mock): void {
            $mock->shouldReceive('refundStorePayment')->once()
                ->andReturn(['automatic' => false, 'refused' => true, 'reference' => null, 'message' => 'window']);
        });

        $balanceBefore = CustomerWalletService::balanceFor($customer);
        $this->assertEqualsWithDelta(0.0, $balanceBefore, 0.001);

        $order->status = 'cancelled';
        $order->save();
        OrderCancellation::reverse($order->fresh());

        $this->assertEqualsWithDelta(
            20.0,
            CustomerWalletService::balanceFor($customer),
            0.001,
            'redeemed value lands in the customer store-credit wallet'
        );
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001, 'tender zeroed');
        $this->assertEqualsWithDelta(0.0, $card->fresh()->balance(), 0.001, 'disabled card stays drained');

        // The fallback branch logs refunded_to_store_credit, NOT refund_due.
        $this->assertTrue(
            ActivityLog::where('action', 'giftcard.refunded_to_store_credit')->where('subject_id', $order->id)->exists(),
            'giftcard.refunded_to_store_credit activity was logged'
        );
        $this->assertSame(0, ActivityLog::where('action', 'giftcard.refund_due')->where('subject_id', $order->id)->count());

        // The merchant → platform clearing out leg still posts once (the inverse of funding).
        $this->assertTrue(
            LedgerEntry::query()->where('ref', 'gc:refund:order:'.$order->id.':card:'.$card->id.':out:from')->exists(),
            'merchant → clearing leg posted once even on the store-credit route'
        );
    }
}
