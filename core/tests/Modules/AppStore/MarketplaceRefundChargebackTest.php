<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\PartnerEarning;
use App\Models\Theme;
use App\Services\SellerPayoutService;
use App\Services\ThemePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\ThemePurchase;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\Disputes\DisputeService;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\TestCase;

/**
 * P6-243: marketplace refunds and chargebacks take back the seller's share exactly once (holdback reversal), flag
 * the admin when it has matured / been spent, and a charged-back wallet top-up debits the wallet (or alerts).
 */
class MarketplaceRefundChargebackTest extends TestCase
{
    use RefreshDatabase;

    private function user(float $balance = 0): User
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        if ($balance > 0) {
            WalletService::credit($user, $balance, 'seed', 'seed-'.$user->id);
        }

        return $user;
    }

    /** @return array{0: User, 1: User, 2: ThemePurchase} seller, buyer, purchase of a 40.00 theme */
    private function themeSale(): array
    {
        $seller = $this->user();
        $buyer = $this->user(100);
        $theme = Theme::create(['name' => 'Paid', 'slug' => 'paid-'.uniqid(), 'status' => 'published', 'price' => 40, 'seller_id' => $seller->id]);
        $purchase = new ThemePurchaseService()->purchase($buyer, $theme);

        return [$seller, $buyer, $purchase];
    }

    public function test_theme_refund_inside_the_holdback_credits_the_buyer_and_debits_the_seller_once(): void
    {
        [$seller, $buyer, $purchase] = $this->themeSale();
        $this->assertSame(28.0, WalletService::balanceFor($seller));
        $this->assertSame(60.0, WalletService::balanceFor($buyer));

        $this->assertTrue(app(ThemePurchaseService::class)->refund($purchase, 1, 'duplicate purchase'));

        $this->assertSame(100.0, WalletService::balanceFor($buyer->fresh()), 'buyer credited the price');
        $this->assertSame(0.0, WalletService::balanceFor($seller->fresh()), 'seller share reversed');
        $this->assertSame('reversed', PartnerEarning::where('source_ref', 'theme-sale:'.$purchase->id)->value('status'));
        $this->assertSame(1, WalletTransaction::where('ref', 'payout:reverse:theme-sale:'.$purchase->id)->count(), 'through reverseSale');
        $this->assertFalse(ThemePurchaseService::entitled($buyer, $purchase->theme), 'licence revoked');
        $this->assertNotNull($purchase->fresh()->refunded_at);

        // Replay / double click: nothing moves again.
        $this->assertFalse(app(ThemePurchaseService::class)->refund($purchase->fresh(), 1, 'again'));
        $this->assertSame(100.0, WalletService::balanceFor($buyer->fresh()));
        $this->assertSame(0.0, WalletService::balanceFor($seller->fresh()));
    }

    public function test_theme_refund_after_the_holdback_with_the_money_spent_flags_the_admin(): void
    {
        [$seller, $buyer, $purchase] = $this->themeSale();
        PartnerEarning::where('source_ref', 'theme-sale:'.$purchase->id)->update(['available_at' => now()->subDay()]);
        WalletService::debit($seller->fresh(), 28.0, 'withdrawal', 'spent-'.$seller->id);

        app(ThemePurchaseService::class)->refund($purchase, 1, 'chargeback threat');

        $this->assertSame(100.0, WalletService::balanceFor($buyer->fresh()), 'buyer still made whole');
        $this->assertSame(0.0, WalletService::balanceFor($seller->fresh()), 'seller never driven negative');
        $this->assertSame('clawback_due', PartnerEarning::where('source_ref', 'theme-sale:'.$purchase->id)->value('status'));
        $this->assertTrue(PlatformAlert::where('kind', 'payment')->where('message', 'like', '%clawback due%')->exists(), 'admin alerted');
    }

    public function test_a_refund_needs_a_reason(): void
    {
        [, , $purchase] = $this->themeSale();

        $this->expectException(\InvalidArgumentException::class);
        app(ThemePurchaseService::class)->refund($purchase, 1, '  ');
    }

    public function test_a_refunded_theme_bought_again_is_charged_and_credited_again(): void
    {
        [$seller, $buyer, $purchase] = $this->themeSale();
        app(ThemePurchaseService::class)->refund($purchase, 1, 'mistake');

        $again = new ThemePurchaseService()->purchase($buyer->fresh(), $purchase->theme);

        $this->assertSame($purchase->id, $again->id);
        $this->assertSame(60.0, WalletService::balanceFor($buyer->fresh()), 'charged again');
        $this->assertSame(28.0, WalletService::balanceFor($seller->fresh()), 'seller credited again');
        $this->assertTrue(ThemePurchaseService::entitled($buyer, $purchase->theme));
        $this->assertSame('theme-sale:'.$purchase->id.':r1', ThemePurchaseService::saleRef($again));
    }

    public function test_reverse_or_flag_is_idempotent_per_ref(): void
    {
        $seller = $this->user();
        SellerPayoutService::creditSale($seller->id, 10.0, 'x:1', 'app sale: x');

        $this->assertSame('reversed', SellerPayoutService::reverseOrFlag('x:1', 'refund'));
        $this->assertSame('reversed', SellerPayoutService::reverseOrFlag('x:1', 'refund'));
        $this->assertSame('none', SellerPayoutService::reverseOrFlag('nope', 'refund'));
        $this->assertSame(0.0, WalletService::balanceFor($seller->fresh()));
    }

    // ── chargebacks ──────────────────────────────────────────────────────────

    private function topup(User $owner, float $amount, string $charge): Transaction
    {
        $tx = Transaction::create([
            'user_id' => $owner->id, 'product_id' => 0, 'amount' => $amount, 'base_amount' => $amount, 'currency_code' => WalletService::currency(),
            'transaction_method' => 'wallet_topup', 'payment_id' => $charge, 'transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS,
        ]);
        WalletService::credit($owner, $amount, 'Wallet top-up', 'topup-txn-'.$tx->id);

        return $tx;
    }

    /** @return array<string, mixed> */
    private function dispute(string $type, string $id, string $charge, string $status): array
    {
        return ['type' => $type, 'data' => ['object' => [
            'id' => $id, 'charge' => $charge, 'amount' => 5000, 'currency' => strtolower(WalletService::currency()),
            'status' => $status, 'reason' => 'fraudulent', 'created' => now()->timestamp,
        ]]];
    }

    public function test_a_charged_back_top_up_is_debited_once_and_given_back_when_won(): void
    {
        $owner = User::factory()->create();
        $this->topup($owner, 50.0, 'ch_top1');
        $this->assertSame(50.0, WalletService::balanceFor($owner));

        $svc = new DisputeService();
        $svc->upsertFromStripe($this->dispute('charge.dispute.created', 'dp_1', 'ch_top1', 'needs_response'));
        $svc->upsertFromStripe($this->dispute('charge.dispute.funds_withdrawn', 'dp_1', 'ch_top1', 'needs_response'));
        $this->assertSame(0.0, WalletService::balanceFor($owner->fresh()), 'debited once');

        $svc->upsertFromStripe($this->dispute('charge.dispute.closed', 'dp_1', 'ch_top1', 'won'));
        $svc->upsertFromStripe($this->dispute('charge.dispute.closed', 'dp_1', 'ch_top1', 'won'));
        $this->assertSame(50.0, WalletService::balanceFor($owner->fresh()), 'won: given back once');
    }

    public function test_a_charged_back_top_up_already_spent_alerts_and_debits_nothing(): void
    {
        $owner = User::factory()->create();
        $this->topup($owner, 50.0, 'ch_top2');
        WalletService::debit($owner, 45.0, 'theme', 'spent-'.$owner->id);

        (new DisputeService())->upsertFromStripe($this->dispute('charge.dispute.created', 'dp_2', 'ch_top2', 'needs_response'));

        $this->assertSame(5.0, WalletService::balanceFor($owner->fresh()));
        $this->assertTrue(PlatformAlert::where('kind', 'payment')->where('message', 'like', '%already spent%')->exists());
    }

    public function test_an_inquiry_debits_nothing(): void
    {
        $owner = User::factory()->create();
        $this->topup($owner, 50.0, 'ch_top3');

        (new DisputeService())->upsertFromStripe($this->dispute('charge.dispute.created', 'dp_3', 'ch_top3', 'warning_needs_response'));

        $this->assertSame(50.0, WalletService::balanceFor($owner->fresh()));
    }

    public function test_a_charged_back_card_app_purchase_reverses_the_developer_share_once(): void
    {
        $developer = $this->user();
        $buyer = User::factory()->create();
        $tx = Transaction::create([
            'user_id' => $buyer->id, 'product_id' => 0, 'amount' => 20.0, 'base_amount' => 20.0, 'currency_code' => WalletService::currency(),
            'transaction_method' => 'app_purchase', 'payment_id' => 'ch_app1', 'transaction_gatway' => 'stripe',
            'details' => ['app_id' => 0], 'status' => Transaction::STATUS_SUCCESS,
        ]);
        SellerPayoutService::creditSale($developer->id, 20.0, 'app-card-txn-'.$tx->id, 'app sale: x', $buyer->id);
        $this->assertSame(14.0, WalletService::balanceFor($developer->fresh()));

        $svc = new DisputeService();
        $svc->upsertFromStripe($this->dispute('charge.dispute.created', 'dp_4', 'ch_app1', 'needs_response'));
        $svc->upsertFromStripe($this->dispute('charge.dispute.updated', 'dp_4', 'ch_app1', 'under_review'));

        $this->assertSame(0.0, WalletService::balanceFor($developer->fresh()));
        $this->assertSame('reversed', PartnerEarning::where('source_ref', 'app-card-txn-'.$tx->id)->value('status'));
    }
}
