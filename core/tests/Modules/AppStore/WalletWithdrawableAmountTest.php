<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\SellerWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\SellerEarnings;
use Tests\TestCase;

/**
 * SEC-1120 review SHOULD 4: only marketplace earnings past the hold can be withdrawn (AUDIT-money L-1). The wallet page
 * shows that withdrawable amount, limits the withdrawal input to it, and no longer says top-ups or referrals can be
 * withdrawn; a refused request says the rest of the balance is spend-only.
 */
class WalletWithdrawableAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        config(['settings.non_active_allow' => 0, 'settings.currency_code' => 'USD']);
    }

    /** A seller with 30.00 of matured earnings and 70.00 of top-up credit (100.00 balance, 30.00 withdrawable). */
    private function seller(): User
    {
        $u = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => 'free', 'user_type' => 'user']);
        SellerEarnings::seedMatured($u, 30);
        WalletService::creditBack($u, 70, 'USD', 'top-up', 'topup:test:'.$u->id);
        $this->assertEqualsWithDelta(100.0, WalletService::balanceFor($u), 0.000001);

        return $u;
    }

    public function test_wallet_page_shows_and_limits_the_withdrawable_amount(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $seller = $this->seller();

        $this->actingAs($seller)->get(route('wallet.index'))->assertOk()
            ->assertSee('max="30"', false)
            ->assertDontSee('max="100"', false)
            ->assertSee('data-wallet-withdrawable', false)
            ->assertSee('Only marketplace earnings can be withdrawn')
            ->assertDontSee('Your balance from top-ups, referrals, refunds, marketplace earnings, and admin grants. Withdraw it');
    }

    public function test_a_refused_withdrawal_says_the_rest_is_spend_only(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $seller = $this->seller();

        $this->actingAs($seller)->from(route('wallet.index'))->post(route('wallet.withdraw'), ['amount' => '50'])
            ->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'can be spent on the platform'));
        $this->assertEqualsWithDelta(100.0, WalletService::balanceFor($seller), 0.000001);
    }

    /**
     * SEC-1120 delta NIT 3: only a withdrawal refusal reads "more than you can withdraw". A failure underneath (here the
     * wallet debit, such as a lock or database error) is not passed off as one.
     */
    public function test_a_wallet_failure_is_not_reported_as_a_refused_withdrawal(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $seller = $this->seller();
        $wallet = \Mockery::mock(Wallet::class);
        $wallet->shouldReceive('currency')->andReturn('USD');
        $wallet->shouldReceive('balanceFor')->andReturn(100.0);
        $wallet->shouldReceive('debit')->andThrow(new \RuntimeException('Lock wait timeout exceeded'));
        $this->app->instance(Wallet::class, $wallet);

        $this->actingAs($seller)->from(route('wallet.index'))->post(route('wallet.withdraw'), ['amount' => '10'])
            ->assertStatus(500)->assertSessionMissing('error');
        $this->assertSame(0, SellerWithdrawal::where('user_id', $seller->id)->count());
    }
}
