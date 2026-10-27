<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coupons & Wallets P6-2: the ledger:reconcile integrity check.
 */
class LedgerReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_ledger_reconciles(): void
    {
        $src = LedgerService::accountFor('platform', 0, 'platform');
        $dst = LedgerService::accountFor('merchant', 1, 'merchant');
        LedgerService::post([
            ['account' => $src, 'amount' => -100, 'allow_negative' => true, 'ref' => 'a'],
            ['account' => $dst, 'amount' => 100, 'ref' => 'b'],
        ]);
        LedgerService::transfer($dst, LedgerService::accountFor('merchant', 2, 'merchant'), 30, 'mv');

        $this->artisan('ledger:reconcile')->assertExitCode(0);
    }

    public function test_unbalanced_transaction_is_detected(): void
    {
        $dst = LedgerService::accountFor('merchant', 1, 'merchant');
        // A lone, unbalanced leg (bypasses the service) — drift.
        LedgerEntry::create([
            'ledger_account_id' => $dst->id, 'amount' => 50, 'balance_after' => 50,
            'transaction_id' => 'orphan', 'type' => 'credit', 'ref' => 'x',
        ]);

        $this->artisan('ledger:reconcile')->assertExitCode(1);
    }

    public function test_bad_balance_snapshot_is_detected(): void
    {
        $src = LedgerService::accountFor('platform', 0, 'platform');
        $dst = LedgerService::accountFor('merchant', 1, 'merchant');
        LedgerService::post([
            ['account' => $src, 'amount' => -100, 'allow_negative' => true, 'ref' => 'a'],
            ['account' => $dst, 'amount' => 100, 'ref' => 'b'],
        ]);
        // Corrupt the latest snapshot on the merchant account.
        LedgerEntry::where('ledger_account_id', $dst->id)->orderByDesc('id')->first()->update(['balance_after' => 999]);

        $this->artisan('ledger:reconcile')->assertExitCode(1);
    }
}
