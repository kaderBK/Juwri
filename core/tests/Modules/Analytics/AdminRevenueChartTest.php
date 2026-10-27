<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Core\Models\User;
use App\Services\AdminDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M10 — the admin revenue chart split by transaction_method, not product_id.
 * Every add-on/store purchase writes product_id=0, so the old split mislabeled
 * add-on revenue as "wallet".
 */
class AdminRevenueChartTest extends TestCase
{
    use RefreshDatabase;

    private function txn(string $method, float $amount, array $details = []): Transaction
    {
        $userId = User::query()->value('id') ?? User::factory()->create()->id;

        return Transaction::create([
            'product_name'       => $method,
            'product_id'         => 0,
            'user_id'            => $userId,
            'base_amount'        => $amount,
            'amount'             => $amount,
            'currency_code'      => 'USD',
            'transaction_method' => $method,
            'payment_id'         => $method . '-' . uniqid(),
            'transaction_gatway' => 'stripe',
            'frequency'          => 'MONTHLY',
            'details'            => $details ?: null,
            'status'             => Transaction::STATUS_SUCCESS,
        ]);
    }

    public function test_addon_revenue_is_charted_as_addon_not_wallet(): void
    {
        AdminDashboardService::flush();
        $this->txn('membership', 100);
        $this->txn('addon', 30);
        $this->txn('app_purchase', 20);
        $this->txn('wallet_topup', 15);

        $charts = new AdminDashboardService()->charts(30);

        $this->assertSame(100.0, $charts['totals']['subscriptions']);
        $this->assertSame(50.0, $charts['totals']['addon']);   // addon + app_purchase
        $this->assertSame(15.0, $charts['totals']['wallet']);  // wallet only, not the add-ons
    }

    public function test_refund_reversal_reduces_its_bucket(): void
    {
        AdminDashboardService::flush();
        $this->txn('addon', 40);
        $this->txn('refund', -40, ['refunded_method' => 'addon', 'refund_of' => 1]);

        $charts = new AdminDashboardService()->charts(30);

        $this->assertSame(0.0, $charts['totals']['addon']); // net of the refund
    }

    public function test_membership_is_plan_revenue(): void
    {
        AdminDashboardService::flush();
        $this->txn('membership', 75);

        $charts = new AdminDashboardService()->charts(30);

        $this->assertSame(75.0, $charts['totals']['subscriptions']);
        $this->assertSame(0.0, $charts['totals']['addon']);
    }
}
