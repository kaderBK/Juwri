<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coupons & Wallets P1-1: the unified ledger schema. No service behavior yet —
 * this pins the tables, models, and the (ledger_account_id, ref) idempotency unique.
 */
class LedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_and_entries_persist(): void
    {
        $account = LedgerAccount::factory()->create(['currency' => 'USD']);

        LedgerEntry::create([
            'ledger_account_id' => $account->id, 'amount' => 100.00, 'balance_after' => 100.00,
            'transaction_id' => 'tx1', 'type' => 'credit', 'reason' => 'seed', 'ref' => 'r1',
        ]);
        LedgerEntry::create([
            'ledger_account_id' => $account->id, 'amount' => -30.00, 'balance_after' => 70.00,
            'transaction_id' => 'tx2', 'type' => 'debit', 'reason' => 'spend', 'ref' => 'r2',
        ]);

        $this->assertSame(2, $account->entries()->count());
        $this->assertEqualsWithDelta(70.00, (float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 0.001);
    }

    public function test_ref_is_unique_per_account(): void
    {
        $account = LedgerAccount::factory()->create();
        LedgerEntry::create([
            'ledger_account_id' => $account->id, 'amount' => 5, 'balance_after' => 5,
            'transaction_id' => 'a', 'type' => 'credit', 'ref' => 'dup',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        LedgerEntry::create([
            'ledger_account_id' => $account->id, 'amount' => 5, 'balance_after' => 10,
            'transaction_id' => 'b', 'type' => 'credit', 'ref' => 'dup',
        ]);
    }

    public function test_same_ref_allowed_on_different_accounts(): void
    {
        $a = LedgerAccount::factory()->create();
        $b = LedgerAccount::factory()->create();

        LedgerEntry::create(['ledger_account_id' => $a->id, 'amount' => 1, 'balance_after' => 1, 'transaction_id' => 't', 'type' => 'credit', 'ref' => 'shared']);
        LedgerEntry::create(['ledger_account_id' => $b->id, 'amount' => 1, 'balance_after' => 1, 'transaction_id' => 't', 'type' => 'credit', 'ref' => 'shared']);

        $this->assertSame(2, LedgerEntry::where('ref', 'shared')->count());
    }

    public function test_owner_kind_currency_unique(): void
    {
        LedgerAccount::create(['owner_type' => 'platform', 'owner_id' => 0, 'kind' => 'platform', 'currency' => 'USD']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        LedgerAccount::create(['owner_type' => 'platform', 'owner_id' => 0, 'kind' => 'platform', 'currency' => 'USD']);
    }
}
