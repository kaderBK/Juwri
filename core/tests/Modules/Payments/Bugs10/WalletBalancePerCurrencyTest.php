<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Bugs10;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\PartnerEarning;
use App\Models\StoreOption;
use App\Services\SellerPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\TestCase;

/**
 * Bug batch 10 · 1 — the merchant wallet (WalletService) added rows of every currency into one balance, and a
 * storefront / payment-page request (which swaps settings.currency_code to the store's currency for display) stamped
 * wallet rows and platform ledger accounts with the STORE's currency. Owner rule: balances are per currency.
 * balanceFor() is the wallet currency's (the platform's) balance, balancesByCurrency() / otherBalances() the rest, a
 * credit in another currency is refused, a debit draws only the matching currency, and money written after a display
 * swap is stamped with the platform currency.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class WalletBalancePerCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        config(['settings.currency_code' => 'USD', 'settings.platform_currency_code' => null]);
    }

    private function user(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => 'free', 'user_type' => 'user']);
    }

    /** A wallet holding USD 10.00 and a legacy KWD 2.500 row (written while the platform billed in KWD). */
    private function mixedWallet(): User
    {
        $u = $this->user();
        WalletService::credit($u, 10, 'seed', 'seed-usd-'.$u->id);
        DB::table('wallet_transactions')->insert(['user_id' => $u->id, 'amount_minor' => 2500, 'balance_after_minor' => 2500,
            'type' => 'credit', 'reason' => 'legacy', 'currency_code' => 'KWD', 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    public function test_balance_is_the_wallet_currency_only_and_the_rest_is_reported_apart(): void
    {
        $u = $this->mixedWallet();

        $this->assertSame(10.0, WalletService::balanceFor($u));                                // before: 12.5 (USD 10 + KWD 2.500)
        $this->assertSame(['USD' => 10.0, 'KWD' => 2.5], WalletService::balancesByCurrency($u)); // before: — (no method)
        $this->assertSame(['KWD' => 2.5], WalletService::otherBalances($u));
        $this->assertSame(2.5, WalletService::balanceFor($u, 'KWD'));
    }

    public function test_a_debit_draws_only_the_matching_currency(): void
    {
        $u = $this->mixedWallet();

        try {
            WalletService::debit($u, 11, 'too much', 'b10-over');
            $this->fail('a KWD balance must not cover a USD debit'); // before: debited (12.5 ≥ 11)
        } catch (\RuntimeException) {
        }
        $this->assertSame(10.0, WalletService::balanceFor($u));

        WalletService::debit($u, 2, 'old funds', 'b10-kwd', 'KWD');
        $this->assertSame(0.5, WalletService::balanceFor($u, 'KWD'));
        $this->assertSame('KWD', WalletTransaction::where('ref', 'b10-kwd')->value('currency_code'));
        $this->assertSame(10.0, WalletService::balanceFor($u), 'the USD balance is untouched');
    }

    public function test_a_credit_in_another_currency_is_refused(): void
    {
        $u = $this->user();
        $this->expectException(\InvalidArgumentException::class);
        WalletService::credit($u, 5, 'kwd money', 'b10-kwd-credit', null, 'KWD');
    }

    public function test_a_storefront_display_swap_does_not_restamp_wallet_money(): void
    {
        $owner = $this->user();
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'b10-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'T',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD');
        $seller = $this->user();

        storefront_apply_store_currency($store->id); // what a storefront JSON endpoint does
        $this->assertSame('KWD', config('settings.currency_code'));
        $this->assertSame('USD', LedgerService::defaultCurrency());          // before: KWD
        SellerPayoutService::creditSale($seller->id, 10, 'b10-sale', 'app sale: x');

        $row = WalletTransaction::where('user_id', $seller->id)->sole();
        $this->assertSame('USD', $row->currency_code);                       // before: KWD
        $this->assertSame('USD', WalletService::currency());
        $this->assertSame([], WalletService::otherBalances($seller));
    }

    public function test_payment_page_swap_keeps_the_platform_currency(): void
    {
        $u = $this->user();
        config(['settings.currency_code' => 'KWD']); // a payment page (Settlement::useStoreCurrency) after remember_platform_currency()
        config(['settings.platform_currency_code' => 'USD']);
        WalletService::credit($u, 3, 'refund', 'b10-ref');
        $this->assertSame('USD', WalletTransaction::where('ref', 'b10-ref')->value('currency_code')); // before: KWD
        $this->assertSame('USD', platform_currency());
    }

    public function test_withdrawal_is_stamped_and_rejected_back_into_its_currency(): void
    {
        $u = $this->mixedWallet();
        // AUDIT-money L-1: only earned money is withdrawable — the seed balances are sale earnings out of the holdback.
        foreach (['USD' => 10, 'KWD' => 2.5] as $code => $net) {
            PartnerEarning::create(['partner_id' => $u->id, 'source_type' => 'theme_sale', 'source_ref' => 'b10-earn-'.$code,
                'gross' => $net, 'share' => 1.0, 'net' => $net, 'currency' => $code, 'status' => 'available', 'available_at' => now()->subDay()]);
        }
        $w = SellerPayoutService::requestWithdrawal($u, 4);
        $this->assertSame('USD', $w->fresh()->currency_code);
        $this->assertSame(6.0, WalletService::balanceFor($u));

        $kw = SellerPayoutService::requestWithdrawal($u, 2.5, null, 'KWD');
        $this->assertSame('KWD', $kw->fresh()->currency_code);
        $this->assertSame(0.0, WalletService::balanceFor($u, 'KWD'));

        SellerPayoutService::rejectWithdrawal($kw, User::factory()->create(['user_type' => 'admin']), 'nope');
        $this->assertSame(2.5, WalletService::balanceFor($u, 'KWD')); // back to the KWD balance
        $this->assertSame(6.0, WalletService::balanceFor($u));
    }

    public function test_owner_wallet_page_shows_other_currency_balances_apart(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $u = $this->mixedWallet();
        $this->actingAs($u)->get(route('wallet.index'))->assertOk()
            ->assertSee('Also held in other currencies')->assertSee('KWD 2.500'); // before: neither (12.50 shown as one balance)
    }
}
