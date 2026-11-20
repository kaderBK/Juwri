<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Bugs9;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\SellerWithdrawal;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\AmountInput;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\SellerEarnings;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Bug batch 9 · 4 — wallet amount inputs (merchant / partner withdrawals, merchant, customer, marketplace and reseller
 * wallet top-ups, reseller → merchant funding, admin reseller wallet grants) stepped by 0.01 whatever the wallet
 * currency, and the server rounded whatever it got (the customer top-up even to 2 decimals: KWD 7.125 → 7.13). Inputs
 * now step by one minor unit of the wallet currency and the server accepts exactly that precision.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class WalletAmountInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        config(['settings.non_active_allow' => 0, 'settings.wallet_topup_enabled' => true, 'settings.wallet_topup_min' => 0.5, 'settings.wallet_topup_max' => 1000]);
    }

    public function test_step_and_rule_follow_the_currency_exponent(): void
    {
        $this->assertSame('0.001', AmountInput::step('KWD'));
        $this->assertSame('0.01', AmountInput::step('USD'));
        $this->assertSame('1', AmountInput::step('JPY'));
        $this->assertSame('1', AmountInput::step('ISK'));
        $this->assertSame('0.001', AmountInput::step('IQD'));
        $this->assertSame('decimal:0,3', AmountInput::precisionRule('KWD'));
        $this->assertSame('integer', AmountInput::precisionRule('JPY'));
        config(['settings.currency_code' => 'BHD']);
        $this->assertSame('0.001', AmountInput::step(null));
    }

    private function seller(float $balance): User
    {
        $u = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => 'free', 'user_type' => 'user']);
        SellerEarnings::seedMatured($u, $balance); // L-1: only earned money is withdrawable

        return $u;
    }

    public function test_withdrawal_accepts_exactly_the_wallet_precision(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        config(['settings.currency_code' => 'KWD']);
        $seller = $this->seller(10);

        $this->actingAs($seller)->post(route('wallet.withdraw'), ['amount' => '1.2345'])->assertSessionHasErrors('amount');
        $this->assertSame(0, SellerWithdrawal::where('user_id', $seller->id)->count()); // before: 1 (1.235 held silently)
        $this->assertEqualsWithDelta(10.0, WalletService::balanceFor($seller), 0.000001); // before: 8.765

        $this->actingAs($seller)->post(route('wallet.withdraw'), ['amount' => '1.235'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(8.765, WalletService::balanceFor($seller), 0.000001);

        config(['settings.currency_code' => 'JPY']);
        $jpy = $this->seller(100);
        $this->actingAs($jpy)->post(route('wallet.withdraw'), ['amount' => '30.5'])->assertSessionHasErrors('amount'); // before: accepted (31 held)
        $this->actingAs($jpy)->post(route('wallet.withdraw'), ['amount' => '30'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(70.0, WalletService::balanceFor($jpy), 0.000001);
    }

    public function test_owner_wallet_pages_step_by_the_minor_unit(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        config(['settings.currency_code' => 'KWD']);
        $seller = $this->seller(10);
        PaymentGateway::where('payment_folder', 'wire_transfer')->update(['payment_install' => '1']);

        $this->actingAs($seller)->get(route('wallet.topup'))->assertOk()->assertSee('step="0.001"', false); // before: step="0.001" (display decimals = exponent for KWD)
        $this->actingAs($seller)->post(route('wallet.topup.pay'), ['amount' => '7.1255', 'payment_method' => PaymentGateway::where('payment_folder', 'wire_transfer')->value('id')])
            ->assertSessionHasErrors('amount'); // before: accepted as 7.126
        $this->assertSame(0, Transaction::where('user_id', $seller->id)->count());
    }

    public function test_customer_top_up_keeps_the_store_currency_precision(): void
    {
        config(['settings.currency_code' => 'USD', 'settings.admin_allow_customer_wallet' => true]);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'b9w-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'T', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD');
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        // P1-23: a top-up is paid through the STORE's own gateway, never a platform one.
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', 'sk_test_store_own');
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_w', 'url' => 'https://checkout.stripe.test/cs_w'])]);
        $gateway = (object) ['id' => 'stripe'];

        $this->actingAs($customer, 'customer')->post(route('publicView.wallet.topup.start', $store->id), ['amount' => '7.1255', 'payment_method' => $gateway->id])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, Transaction::where('transaction_method', 'cust_wallet_topup')->count()); // before: 1

        $this->actingAs($customer, 'customer')->post(route('publicView.wallet.topup.start', $store->id), ['amount' => '7.125', 'payment_method' => $gateway->id]);
        $txn = Transaction::where('transaction_method', 'cust_wallet_topup')->sole();
        $this->assertSame('KWD', $txn->currency_code);
        $this->assertSame((int) $store->id, (int) $txn->store_id);
        $this->assertEqualsWithDelta(7.125, (float) $txn->amount, 0.0000001); // before: 7.13
    }
}
