<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\PartnerEarning;
use App\Models\SellerWithdrawal;
use App\Services\SellerPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\SellerStripeAccount;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\Payouts\StripeConnectGateway;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\TestCase;

/**
 * P6-230: pay / reject run once under a row lock (a stale second click is refused before any side effect).
 * P6-231: sale credits stay in a 14-day holdback; a refunded sale is reversed while held.
 */
class SellerWithdrawalSafetyTest extends TestCase
{
    use RefreshDatabase;

    public int $transfers = 0;

    private function seller(float $balance = 0): User
    {
        $seller = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        if ($balance > 0) {
            WalletService::credit($seller, $balance, 'seed', 'seed:'.$seller->id);
        }

        return $seller;
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    /**
     * Two admin tabs holding the same pending row (the double click).
     *
     * @return array{SellerWithdrawal, SellerWithdrawal}
     */
    private function twoCopies(SellerWithdrawal $w): array
    {
        return [SellerWithdrawal::findOrFail($w->id), SellerWithdrawal::findOrFail($w->id)];
    }

    private function bindCountingGateway(): void
    {
        $test = $this;
        $this->app->instance(StripeConnectGateway::class, new class($test) implements StripeConnectGateway
        {
            public function __construct(private SellerWithdrawalSafetyTest $test) {}

            public function createTransfer(int $amountCents, string $currency, string $destinationAccount, string $idempotencyKey): string
            {
                $this->test->transfers++;

                return 'tr_'.$this->test->transfers;
            }

            public function createExpressAccount(string $email): string
            {
                return 'acct_x';
            }

            public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
            {
                return 'https://connect.stripe.test/x';
            }

            public function accountDetailsSubmitted(string $accountId): bool
            {
                return true;
            }
        });
    }

    public function test_double_click_pay_via_stripe_transfers_once(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect']);
        $this->bindCountingGateway();
        $seller = $this->seller(50);
        SellerStripeAccount::create(['user_id' => $seller->id, 'stripe_account_id' => 'acct_s', 'details_submitted' => true]);
        [$a, $b] = $this->twoCopies(SellerPayoutService::requestWithdrawal($seller, 30));
        $admin = $this->admin();

        SellerPayoutService::payViaStripe($a, $admin);
        try {
            SellerPayoutService::payViaStripe($b, $admin);
            $this->fail('second pay must be refused');
        } catch (\RuntimeException $e) {
        }

        $this->assertSame(1, $this->transfers);
        $this->assertSame('tr_1', $b->fresh()->reference);
    }

    public function test_pay_then_stale_reject_does_not_credit_back(): void
    {
        $seller = $this->seller(50);
        [$a, $b] = $this->twoCopies(SellerPayoutService::requestWithdrawal($seller, 30));
        $admin = $this->admin();

        SellerPayoutService::markPaid($a, $admin, 'BANK-1');
        try {
            SellerPayoutService::rejectWithdrawal($b, $admin, 'late');
            $this->fail('reject after pay must be refused');
        } catch (\RuntimeException $e) {
        }

        $this->assertSame('paid', $a->fresh()->status);
        $this->assertEqualsWithDelta(20.00, WalletService::balanceFor($seller), 0.001, 'no refund after payment');
    }

    public function test_double_reject_credits_back_once(): void
    {
        $seller = $this->seller(50);
        [$a, $b] = $this->twoCopies(SellerPayoutService::requestWithdrawal($seller, 30));
        $admin = $this->admin();

        SellerPayoutService::rejectWithdrawal($a, $admin);
        try {
            SellerPayoutService::rejectWithdrawal($b, $admin);
            $this->fail('second reject must be refused');
        } catch (\RuntimeException $e) {
        }

        $this->assertEqualsWithDelta(50.00, WalletService::balanceFor($seller), 0.001);
        $this->assertSame(1, WalletTransaction::where('reason', 'withdrawal_rejected')->count());
    }

    public function test_failed_transfer_leaves_the_withdrawal_pending(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect']);
        $this->bindCountingGateway();
        $seller = $this->seller(50);
        $w = SellerPayoutService::requestWithdrawal($seller, 30); // no connected account: transfer throws

        try {
            SellerPayoutService::payViaStripe($w, $this->admin());
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
        }

        $this->assertSame('pending', $w->fresh()->status);
    }

    public function test_day_one_sale_is_not_withdrawable_day_fifteen_is(): void
    {
        $seller = $this->seller();
        $buyer = $this->seller();
        $credited = SellerPayoutService::creditSale($seller->id, 100.00, 'theme-sale:1', 'theme sale: t', $buyer->id);
        $this->assertGreaterThan(0, $credited);

        $this->assertEqualsWithDelta(0.0, SellerPayoutService::availableBalance($seller), 0.001);
        try {
            SellerPayoutService::requestWithdrawal($seller, $credited);
            $this->fail('day-1 withdrawal must be refused');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame(0, SellerWithdrawal::where('user_id', $seller->id)->count());

        $this->travel(15)->days();
        $this->assertEqualsWithDelta($credited, SellerPayoutService::availableBalance($seller), 0.001);
        $this->assertSame('pending', SellerPayoutService::requestWithdrawal($seller, $credited)->status);
    }

    public function test_held_money_cannot_be_withdrawn_alongside_matured_money(): void
    {
        $seller = $this->seller(40); // matured / older money
        $buyer = $this->seller();
        SellerPayoutService::creditSale($seller->id, 100.00, 'theme-sale:2', 'theme sale: t', $buyer->id);

        $this->assertEqualsWithDelta(40.00, SellerPayoutService::availableBalance($seller), 0.001);
        $this->expectException(\RuntimeException::class);
        SellerPayoutService::requestWithdrawal($seller, 40.01);
    }

    public function test_refunded_sale_is_reversed_and_never_withdrawable(): void
    {
        $seller = $this->seller();
        $buyer = $this->seller();
        $credited = SellerPayoutService::creditSale($seller->id, 100.00, 'theme-sale:3', 'theme sale: t', $buyer->id);

        $this->assertTrue(SellerPayoutService::reverseSale('theme-sale:3', 'theme refund'));
        $this->assertFalse(SellerPayoutService::reverseSale('theme-sale:3', 'theme refund'), 'idempotent');

        $this->assertSame('reversed', PartnerEarning::where('source_ref', 'theme-sale:3')->value('status'));
        $this->assertEqualsWithDelta(0.0, WalletService::balanceFor($seller), 0.001, 'credit taken back once');
        $this->travel(15)->days();
        $this->assertEqualsWithDelta(0.0, SellerPayoutService::availableBalance($seller), 0.001);
        $this->assertGreaterThan(0, $credited);
    }
}
