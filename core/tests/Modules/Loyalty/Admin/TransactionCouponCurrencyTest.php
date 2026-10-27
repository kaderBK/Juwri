<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty\Admin;

use App\Models\Coupon;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Core\Models\User;
use App\Support\Money\TransactionCouponLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Feature\Admin\InteractsWithAdminPanel;

/**
 * Bug: the admin transaction details panel and the invoice printed every fixed coupon as "<number>$" — a KWD or
 * JPY coupon showed a dollar sign, and free_delivery/bogo coupons were subtracted as if their number were an
 * amount. Now: a fixed coupon reads in its own currency with that currency's decimals, a percent as N%.
 */
class TransactionCouponCurrencyTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
    }

    /** @param array<string, mixed>|null $coupon */
    private function transaction(User $user, ?array $coupon, float $base = 100): Transaction
    {
        return Transaction::forceCreate([
            'user_id' => $user->id, 'status' => Transaction::STATUS_SUCCESS, 'amount' => $base, 'base_amount' => $base,
            'currency_code' => 'USD', 'product_name' => 'Some Plan', 'product_id' => 0, 'transaction_description' => 'Plan',
            'transaction_gatway' => 'stripe', 'created_at' => now(), 'coupon' => $coupon,
        ]);
    }

    public function test_coupon_line_per_type_and_currency(): void
    {
        $buyer = $this->plainUser();
        $line = fn (?array $c, float $base = 100) => TransactionCouponLine::for($this->transaction($buyer, $c, $base));

        $this->assertSame(['code' => 'HALF', 'label' => '12.5%', 'discount' => 12.5], $line(['id' => 1, 'code' => 'HALF', 'percentage' => 12.5, 'coupon_type' => 'percentage']));
        $this->assertSame(['code' => 'U', 'label' => '4.99 USD', 'discount' => 4.99], $line(['code' => 'U', 'percentage' => 4.99, 'coupon_type' => 'fixed', 'currency_code' => 'USD']));
        $this->assertSame(['code' => 'K', 'label' => '1.125 KWD', 'discount' => 1.125], $line(['code' => 'K', 'percentage' => 1.125, 'coupon_type' => 'fixed', 'currency_code' => 'KWD']));
        // A snapshot written before currency_code was recorded: the coupon row's currency.
        $yen = Coupon::create(['code' => 'YEN', 'coupon_type' => 'fixed', 'percentage' => 500]);
        DB::table('coupons')->where('id', $yen->id)->update(['currency_code' => 'JPY', 'amount_minor' => 500]); // a JPY issuer's row
        $this->assertSame(['code' => 'YEN', 'label' => '500 JPY', 'discount' => 100.0], $line(['id' => $yen->id, 'code' => 'YEN', 'percentage' => 500, 'coupon_type' => 'fixed']));
        // No coupon row either: the transaction's currency.
        $this->assertSame('3.00 USD', $line(['id' => 999999, 'code' => 'X', 'percentage' => 3, 'coupon_type' => 'fixed'])['label']);
        // free_delivery / bogo give no plan discount (before: their number was subtracted as an amount).
        $this->assertSame(['code' => 'FD', 'label' => '', 'discount' => 0.0], $line(['code' => 'FD', 'percentage' => 5, 'coupon_type' => 'free_delivery']));
        $this->assertNull($line(null));
    }

    public function test_details_panel_and_invoice_show_the_coupons_own_currency(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $admin = $this->admin();
        $buyer = $this->plainUser();
        $kwd = $this->transaction($buyer, ['code' => 'FILS', 'percentage' => 1.125, 'coupon_type' => 'fixed', 'currency_code' => 'KWD']);

        $this->actingAs($admin)->get(route('admin.transactions.show', $kwd))->assertOk()
            ->assertSee('(1.125 KWD)')->assertDontSee('1.125$'); // before: "(1.125$)"
        $this->actingAs($admin)->get(route('invoice', $kwd))->assertOk()
            ->assertSee('(1.125 KWD)')->assertDontSee('1.125$');

        $pct = $this->transaction($buyer, ['code' => 'HALF', 'percentage' => 12.5, 'coupon_type' => 'percentage']);
        $this->actingAs($admin)->get(route('admin.transactions.show', $pct))->assertOk()->assertSee('(12.5%)');
        $this->actingAs($admin)->get(route('invoice', $pct))->assertOk()->assertSee('(12.5%)');
    }
}
