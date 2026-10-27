<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D1 — loyalty accounting invariant.
 *
 * A loyalty account is a ledger head: every mutation goes through earn() /
 * redeem() / redeemForReward() / creditOnce() / reverseEarn(), and each keeps
 * the account columns tied together by the accounting identity
 *
 *     balance == lifetime_earned − lifetime_redeemed      (balance may go negative only via a refund claw-back debt, J46)
 *
 * The demo customer #93's crave row violated this (balance 50 but earned 150 /
 * redeemed 200) — bad hand-seeded data, NOT a code bug: the model paths below
 * can never produce it. This test pins the invariant so a future change to the
 * ledger logic that breaks it fails here, and proves over-redeeming is rejected
 * rather than driving the balance negative.
 *
 * (Expiry via expireStale() deliberately reduces balance without touching the
 * lifetime columns — a documented exception — so it is out of this invariant's
 * scope and not exercised here.)
 */
class LoyaltyAccountInvariantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    /** No FK on customer_id/store_id — a bare account row is enough. */
    private function account(): LoyaltyAccount
    {
        return LoyaltyAccount::forCustomer(1, 1);
    }

    private function assertInvariant(LoyaltyAccount $a, string $ctx = ''): void
    {
        $a->refresh();
        $this->assertSame(
            (int) $a->lifetime_earned - (int) $a->lifetime_redeemed,
            (int) $a->balance,
            "balance == earned − redeemed {$ctx}"
        );
        $this->assertGreaterThanOrEqual(0, (int) $a->balance, "balance >= 0 {$ctx}");
    }

    public function test_earn_then_redeem_keeps_the_invariant(): void
    {
        $a = $this->account();
        $this->assertInvariant($a, '(fresh)');

        $a->earn(5001, 150, 'earn');
        $this->assertInvariant($a, '(after earn 150)');
        $this->assertSame(150, (int) $a->fresh()->balance);

        $a->redeem(100, 'redeem 100');
        $this->assertInvariant($a, '(after redeem 100)');
        $this->assertSame(50, (int) $a->fresh()->balance);
        $this->assertSame(150, (int) $a->fresh()->lifetime_earned);
        $this->assertSame(100, (int) $a->fresh()->lifetime_redeemed);
    }

    public function test_creditOnce_and_reverseEarn_keep_the_invariant(): void
    {
        $a = $this->account();
        $a->earn(6001, 40, 'earn');
        $a->creditOnce(10, 'referral bonus');
        $this->assertInvariant($a, '(after creditOnce)');
        $this->assertSame(50, (int) $a->fresh()->balance);

        // Reversing the earn claws its delta out of both balance and earned.
        $a->reverseEarn(6001, 'reverse');
        $this->assertInvariant($a, '(after reverseEarn)');
        $this->assertSame(10, (int) $a->fresh()->balance);
    }

    public function test_redeeming_more_than_the_balance_is_rejected(): void
    {
        $a = $this->account();
        $a->earn(7001, 30, 'earn');

        // Checkout redeem: returns false, writes nothing, leaves the row intact.
        $this->assertFalse($a->redeem(31, 'over'), 'over-redeem rejected');
        $this->assertInvariant($a, '(after rejected redeem)');
        $this->assertSame(30, (int) $a->fresh()->balance);

        // Standalone reward redeem: reports insufficient, never goes negative.
        $this->assertSame('insufficient', $a->redeemForReward(31));
        $this->assertInvariant($a, '(after rejected reward redeem)');
        $this->assertSame(30, (int) $a->fresh()->balance);
        $this->assertSame(0, LoyaltyLedger::where('loyalty_account_id', $a->id)
            ->where('type', 'redeem')->count(), 'no redeem row written');
    }

    public function test_restoreRedeem_gives_back_points_spent_on_an_order(): void
    {
        $a = $this->account();
        $a->earn(8001, 150, 'earn');
        $a->redeem(100, 'checkout', 42); // spends 100 on order #42
        $this->assertInvariant($a, '(after redeem)');
        $this->assertSame(50, (int) $a->fresh()->balance);

        // Order #42 reversed → the 100 spent points come back.
        $this->assertSame(100, $a->restoreRedeem(42), 'restored the redeemed points');
        $this->assertInvariant($a, '(after restoreRedeem)');
        $this->assertSame(150, (int) $a->fresh()->balance, 'points returned');
        $this->assertSame(0, (int) $a->fresh()->lifetime_redeemed, 'redeemed tally unwound');
    }

    public function test_restoreRedeem_is_idempotent_and_ignores_unrelated_orders(): void
    {
        $a = $this->account();
        $a->earn(8101, 150, 'earn');
        $a->redeem(100, 'checkout', 55);

        $this->assertSame(100, $a->restoreRedeem(55), 'first restore returns the points');
        $this->assertSame(0, $a->restoreRedeem(55), 'second restore is a no-op');
        $this->assertSame(150, (int) $a->fresh()->balance, 'not restored twice');

        // An order that redeemed nothing (or a standalone, order-less redeem) is not restorable.
        $this->assertSame(0, $a->restoreRedeem(9999), 'unknown order restores nothing');
    }

    public function test_redeem_is_idempotent_per_owning_order(): void
    {
        $a = $this->account();
        $a->earn(8201, 100, 'earn');

        $this->assertTrue($a->redeem(30, 'checkout', 77), 'first checkout redeem debits');
        $this->assertFalse($a->redeem(30, 'checkout retry', 77), 'a retried order does not double-debit');
        $this->assertSame(70, (int) $a->fresh()->balance, 'debited exactly once');
        $this->assertSame(1, LoyaltyLedger::where('loyalty_account_id', $a->id)
            ->where('type', 'redeem')->where('redeemed_order_id', 77)->count(), 'one redeem row for the order');
    }

    public function test_order_less_redeem_is_not_restorable(): void
    {
        $a = $this->account();
        $a->earn(8301, 100, 'earn');
        $a->redeem(40, 'standalone-style, no order id'); // orderId null

        // No redeemed_order_id → nothing to locate, nothing restored.
        $this->assertSame(0, $a->restoreRedeem(0));
        $this->assertSame(60, (int) $a->fresh()->balance, 'balance unchanged by a no-op restore');
    }

    /** P6-240: dedup is keyed on the order, not the note — two callers can't double-reverse. */
    public function test_reversal_with_different_notes_happens_once(): void
    {
        $a = $this->account();
        $a->earn(9101, 40, 'earn');
        $this->assertTrue($a->reverseEarn(9101, 'Reversed earn — refund — Order #9101'));
        $this->assertFalse($a->reverseEarn(9101, 'a different caller note'));
        $this->assertSame(0, (int) $a->fresh()->balance);
        $this->assertInvariant($a, '(double reversal)');

        $a->earn(9102, 100, 'earn');
        $a->redeem(60, 'checkout', 9103);
        $this->assertSame(60, $a->restoreRedeem(9103, 'note one'));
        $this->assertSame(0, $a->restoreRedeem(9103, 'note two'), 'restore is once per order');
        $this->assertSame(100, (int) $a->fresh()->balance);
    }

    /** P6-240: a legacy note-keyed reversal row (pre-key) still counts — no second claw-back. */
    public function test_legacy_note_keyed_reversal_is_respected(): void
    {
        $a = $this->account();
        $a->earn(9201, 40, 'earn');
        LoyaltyLedger::create(['loyalty_account_id' => $a->id, 'order_id' => null, 'type' => 'adjustment',
            'delta' => -40, 'balance_after' => 0, 'note' => 'Reversed earn — refund — Order #9201']);
        LoyaltyAccount::whereKey($a->id)->update(['balance' => 0, 'lifetime_earned' => 0]);
        $this->assertFalse($a->reverseEarn(9201));
        $this->assertSame(0, (int) $a->fresh()->balance);
    }
}
