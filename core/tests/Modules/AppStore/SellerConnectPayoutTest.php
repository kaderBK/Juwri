<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\SellerPayoutService;
use App\Services\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\SellerStripeAccount;
use Juwri\Modules\Payments\Services\Payouts\StripeConnectGateway;
use Juwri\Modules\Payments\Services\WalletService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P9-5b: the Stripe Connect Express cash-out rail. Money still leaves the wallet
 * at request time (P9-5a); this rail settles a pending withdrawal via a Stripe
 * Transfer to the seller's connected account, behind a mockable gateway. The
 * live Stripe onboarding/transfer is the owner's; here the fake proves routing.
 */
class SellerConnectPayoutTest extends TestCase
{
    use RefreshDatabase;

    private array $captured = [];

    private function bindFakeGateway(string $transferId = 'tr_fake_123'): void
    {
        $test = $this;
        $this->app->instance(StripeConnectGateway::class, new class($test, $transferId) implements StripeConnectGateway
        {
            public function __construct(private $test, private string $transferId) {}

            public function createTransfer(int $amountCents, string $currency, string $destinationAccount, string $idempotencyKey): string
            {
                $this->test->capture(compact('amountCents', 'currency', 'destinationAccount', 'idempotencyKey'));

                return $this->transferId;
            }

            public function createExpressAccount(string $email): string
            {
                return 'acct_fake_new';
            }

            public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
            {
                return 'https://connect.stripe.test/onboard/'.$accountId;
            }

            public function accountDetailsSubmitted(string $accountId): bool
            {
                return true;
            }
        });
    }

    public function capture(array $args): void
    {
        $this->captured = $args;
    }

    private function seller(float $balance): User
    {
        $seller = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        WalletService::credit($seller, $balance, 'seed', 'seed:'.$seller->id);

        return $seller;
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_active_rail_defaults_to_manual(): void
    {
        config(['platform.marketplace.payout_rail' => 'manual']);
        $this->assertSame('manual', StripeConnectService::activeRail());
        $this->assertFalse(StripeConnectService::isAvailable());
    }

    public function test_request_uses_the_active_rail(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect']);
        $seller = $this->seller(50.00);

        $w = SellerPayoutService::requestWithdrawal($seller, 20.00);

        $this->assertSame('stripe_connect', $w->rail);
        $this->assertEqualsWithDelta(30.00, WalletService::balanceFor($seller), 0.001, 'wallet debited at request');
    }

    public function test_stripe_rail_pays_via_transfer_and_records_the_id(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect', 'platform.marketplace.payout_currency' => 'usd']);
        $this->bindFakeGateway('tr_ABC');
        $seller = $this->seller(50.00);
        $admin = $this->admin();
        SellerStripeAccount::create(['user_id' => $seller->id, 'stripe_account_id' => 'acct_seller1', 'details_submitted' => true]);

        $w = SellerPayoutService::requestWithdrawal($seller, 30.00);
        $balanceAfterRequest = WalletService::balanceFor($seller);

        SellerPayoutService::payViaStripe($w, $admin);
        $w->refresh();

        $this->assertSame('paid', $w->status);
        $this->assertSame('tr_ABC', $w->reference, 'transfer id recorded as reference');
        $this->assertEqualsWithDelta($balanceAfterRequest, WalletService::balanceFor($seller), 0.001, 'no second debit on settle');

        // gateway received the right transfer args
        $this->assertSame(3000, $this->captured['amountCents']);
        $this->assertSame(strtolower(WalletService::currency()), $this->captured['currency'], 'P6-232: the withdrawal currency');
        $this->assertSame('acct_seller1', $this->captured['destinationAccount']);
        $this->assertSame('withdraw-payout:'.$w->id, $this->captured['idempotencyKey']);
    }

    public function test_stripe_payout_without_connected_account_fails(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect']);
        $this->bindFakeGateway();
        $seller = $this->seller(50.00);
        $admin = $this->admin();
        $w = SellerPayoutService::requestWithdrawal($seller, 10.00);

        $this->expectException(\RuntimeException::class);
        SellerPayoutService::payViaStripe($w, $admin); // no SellerStripeAccount
    }

    /** Lane D: the transfer amount is in Stripe's minor unit for the payout currency, not always ×100. */
    #[DataProvider('payoutMinorUnits')]
    public function test_stripe_payout_uses_the_currency_minor_unit(string $currency, float $amount, int $expected): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect', 'platform.marketplace.payout_currency' => $currency]);
        $this->bindFakeGateway('tr_MU');
        // P6-232: the transfer is in the withdrawal's currency, so the seller holds (and withdraws) that currency.
        $seller = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        WalletService::creditBack($seller, 2000.00, strtoupper($currency), 'seed', 'seed:'.$seller->id);
        SellerStripeAccount::create(['user_id' => $seller->id, 'stripe_account_id' => 'acct_mu', 'details_submitted' => true]);

        $w = SellerPayoutService::requestWithdrawal($seller, $amount, null, $currency);
        SellerPayoutService::payViaStripe($w, $this->admin());

        $this->assertSame($expected, $this->captured['amountCents']);
        $this->assertSame($currency, $this->captured['currency']);
    }

    /** P6-232: a SAR withdrawal is transferred as SAR even when the configured payout currency is USD. */
    public function test_sar_withdrawal_pays_out_in_sar(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect', 'platform.marketplace.payout_currency' => 'usd']);
        $this->bindFakeGateway('tr_SAR');
        $seller = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        WalletService::creditBack($seller, 500.00, 'SAR', 'seed', 'seed:sar:'.$seller->id);
        SellerStripeAccount::create(['user_id' => $seller->id, 'stripe_account_id' => 'acct_sar', 'details_submitted' => true]);

        $w = SellerPayoutService::requestWithdrawal($seller, 375.50, null, 'SAR');
        SellerPayoutService::payViaStripe($w, $this->admin());

        $this->assertSame('sar', $this->captured['currency']);
        $this->assertSame(37550, $this->captured['amountCents']);
        $this->assertSame('paid', $w->fresh()->status);
    }

    /** @return array<string, array{string, float, int}> */
    public static function payoutMinorUnits(): array
    {
        return ['usd' => ['usd', 30.00, 3000], 'jpy' => ['jpy', 500, 500], 'kwd' => ['kwd', 1.23, 1230]];
    }

    public function test_a_test_key_is_not_available_while_sandboxes_are_off(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect', 'settings.stripe_secret_key' => 'sk_test_x', 'payments.sandbox.enabled' => false]);
        $this->assertFalse(StripeConnectService::isAvailable());

        config(['settings.stripe_secret_key' => 'sk_live_x']);
        $this->assertTrue(StripeConnectService::isAvailable());
    }

    public function test_is_available_requires_the_platform_key(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect', 'settings.stripe_secret_key' => null]);
        $this->assertFalse(StripeConnectService::isAvailable(), 'no key => unavailable');

        config(['settings.stripe_secret_key' => 'sk_test_x']);
        $this->assertTrue(StripeConnectService::isAvailable(), 'key + SDK + rail => available');
    }

    public function test_manual_rail_is_unaffected(): void
    {
        config(['platform.marketplace.payout_rail' => 'manual']);
        $seller = $this->seller(50.00);
        $admin = $this->admin();

        $w = SellerPayoutService::requestWithdrawal($seller, 15.00);
        $this->assertSame('manual', $w->rail);

        SellerPayoutService::markPaid($w, $admin, 'BANK-REF');
        $this->assertSame('paid', $w->fresh()->status);
    }

    // --- Onboarding UI (seller connects their Express account) -----------------

    private function stripeOn(): void
    {
        config(['platform.marketplace.payout_rail' => 'stripe_connect', 'settings.stripe_secret_key' => 'sk_test_x']);
        $this->bindFakeGateway();
    }

    public function test_begin_onboarding_creates_account_once_and_returns_link(): void
    {
        $this->stripeOn();
        $seller = $this->seller(10.00);

        $url = StripeConnectService::beginOnboarding($seller, 'https://x/refresh', 'https://x/return');

        $this->assertSame('https://connect.stripe.test/onboard/acct_fake_new', $url);
        $this->assertSame(1, SellerStripeAccount::where('user_id', $seller->id)->count());
        $this->assertFalse((bool) StripeConnectService::accountFor($seller)->details_submitted);

        // second call reuses the row (no duplicate account)
        StripeConnectService::beginOnboarding($seller, 'https://x/refresh', 'https://x/return');
        $this->assertSame(1, SellerStripeAccount::where('user_id', $seller->id)->count());
    }

    public function test_connect_route_redirects_to_the_hosted_flow(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->stripeOn();
        $seller = $this->seller(10.00);

        $res = $this->actingAs($seller)->post(route('wallet.stripe.connect'));

        $res->assertRedirect('https://connect.stripe.test/onboard/acct_fake_new');
    }

    public function test_return_route_syncs_completion(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->stripeOn();
        $seller = $this->seller(10.00);
        SellerStripeAccount::create(['user_id' => $seller->id, 'stripe_account_id' => 'acct_x', 'details_submitted' => false]);

        $res = $this->actingAs($seller)->get(route('wallet.stripe.return'));

        $res->assertRedirect(route('wallet.index'));
        $this->assertTrue((bool) StripeConnectService::accountFor($seller)->details_submitted, 'fake reports submitted → synced');
    }

    public function test_connect_route_rejected_when_rail_unavailable(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        config(['platform.marketplace.payout_rail' => 'manual']);
        $seller = $this->seller(10.00);

        $this->actingAs($seller)->from(route('wallet.index'))->post(route('wallet.stripe.connect'))
            ->assertRedirect(route('wallet.index'));
        $this->assertSame(0, SellerStripeAccount::where('user_id', $seller->id)->count(), 'no account created');
    }
}
