<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\SellerWithdrawal;
use Juwri\Core\Models\User;
use App\Services\SellerPayoutService;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P9-5a: seller cash-out (manual admin payout). Withdrawals debit the wallet at
 * request time through the money choke point, so they are double-spend-safe;
 * mark-paid only records the settlement; reject refunds the wallet.
 */
class SellerWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function seller(float $balance): User
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

    public function test_request_over_balance_is_rejected_and_leaves_no_row(): void
    {
        $seller = $this->seller(10.00);

        $this->expectException(\RuntimeException::class);
        try {
            SellerPayoutService::requestWithdrawal($seller, 25.00);
        } finally {
            $this->assertSame(0, SellerWithdrawal::where('user_id', $seller->id)->count(), 'no pending row on failure');
            $this->assertSame(10.00, WalletService::balanceFor($seller), 'balance untouched');
        }
    }

    public function test_valid_request_creates_pending_and_debits_once(): void
    {
        $seller = $this->seller(50.00);

        $w = SellerPayoutService::requestWithdrawal($seller, 30.00);

        $this->assertSame('pending', $w->status);
        $this->assertSame('manual', $w->rail);
        $this->assertEqualsWithDelta(20.00, WalletService::balanceFor($seller), 0.001, 'wallet debited exactly once');
    }

    public function test_mark_paid_records_reference_without_touching_wallet(): void
    {
        $seller = $this->seller(50.00);
        $admin = $this->admin();
        $w = SellerPayoutService::requestWithdrawal($seller, 30.00);
        $balanceAfterRequest = WalletService::balanceFor($seller);

        SellerPayoutService::markPaid($w, $admin, 'BANK-REF-123');
        $w->refresh();

        $this->assertSame('paid', $w->status);
        $this->assertSame('BANK-REF-123', $w->reference);
        $this->assertSame($admin->id, (int) $w->processed_by);
        $this->assertEqualsWithDelta($balanceAfterRequest, WalletService::balanceFor($seller), 0.001, 'no second debit on pay');
    }

    public function test_reject_refunds_the_wallet(): void
    {
        $seller = $this->seller(50.00);
        $admin = $this->admin();
        $w = SellerPayoutService::requestWithdrawal($seller, 30.00);

        SellerPayoutService::rejectWithdrawal($w, $admin, 'bad bank details');
        $w->refresh();

        $this->assertSame('rejected', $w->status);
        $this->assertSame('bad bank details', $w->note);
        $this->assertEqualsWithDelta(50.00, WalletService::balanceFor($seller), 0.001, 'amount refunded to wallet');
    }

    public function test_cannot_pay_or_reject_a_non_pending_withdrawal(): void
    {
        $seller = $this->seller(50.00);
        $admin = $this->admin();
        $w = SellerPayoutService::requestWithdrawal($seller, 10.00);
        SellerPayoutService::markPaid($w, $admin, 'REF');

        $this->expectException(\RuntimeException::class);
        SellerPayoutService::markPaid($w->fresh(), $admin, 'REF2');
    }

    public function test_two_requests_cannot_double_spend(): void
    {
        $seller = $this->seller(50.00);

        SellerPayoutService::requestWithdrawal($seller, 40.00); // ok, 10 left
        $this->assertEqualsWithDelta(10.00, WalletService::balanceFor($seller), 0.001);

        $this->expectException(\RuntimeException::class);
        SellerPayoutService::requestWithdrawal($seller, 40.00); // would overdraw
    }

    public function test_admin_withdrawal_routes_are_admin_gated(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $router = app('router');
        foreach (['admin.withdrawals.index', 'admin.withdrawals.pay', 'admin.withdrawals.reject'] as $name) {
            $route = collect($router->getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === $name);
            $this->assertNotNull($route, "route $name exists");
            $mw = $route->gatherMiddleware();
            $gated = in_array('admin', $mw, true)
                || in_array(\App\Http\Middleware\AdminMiddleware::class, $mw, true);
            $this->assertTrue($gated, "$name must be superadmin-gated (has: ".implode(',', $mw).')');
        }
    }

    public function test_seller_request_route_creates_withdrawal_and_debits(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        $seller = $this->seller(50.00);

        $res = $this->actingAs($seller)->post(route('wallet.withdraw'), ['amount' => 30.00]);
        $res->assertRedirect();

        $this->assertSame(1, SellerWithdrawal::where('user_id', $seller->id)->count());
        $this->assertEqualsWithDelta(20.00, WalletService::balanceFor($seller), 0.001);
    }
}
