<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Money;

/**
 * Coupons & Wallets P6-2: verify unified-ledger integrity. Two invariants:
 *   1. every transaction_id group of legs sums to zero (double-entry), and
 *   2. each account's SUM(amount) equals its latest entry's balance_after snapshot.
 * Read-only — it reports drift and exits non-zero; it never rewrites the ledger.
 */
class LedgerReconcile extends Command
{
    protected $signature = 'ledger:reconcile {--fix : report only; the ledger is append-only and never auto-rewritten}';

    protected $description = 'Verify ledger integrity (zero-sum transactions + balance_after snapshots)';

    public function handle(): int
    {
        $issues = 0;

        // 1. Zero-sum per transaction group. Bug batch 4 · 1: exact, in integer minor units — a move has one
        //    currency and every leg is posted at its exponent, so SUM(amount_minor) is 0 (no float tolerance).
        $unbalanced = DB::table('ledger_entries')
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, SUM(amount_minor) as s, MIN(currency_code) as c')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->get();
        foreach ($unbalanced as $t) {
            $got = Money::majorFromMinor((int) $t->s, $t->c ?: null);
            $this->warn("transaction {$t->transaction_id} does not sum to zero (got {$got})");
            $issues++;
        }

        // 2. Per-account SUM(amount) == latest balance_after, compared in the account currency's minor units.
        foreach (LedgerAccount::pluck('currency', 'id') as $accountId => $currency) {
            $sum = (int) LedgerEntry::where('ledger_account_id', $accountId)->sum('amount_minor');
            $latest = LedgerEntry::where('ledger_account_id', $accountId)->orderByDesc('id')->value('balance_after_minor');
            if ($latest !== null && $sum !== (int) $latest) {
                $sumMajor = Money::majorFromMinor($sum, $currency);
                $latestMajor = Money::majorFromMinor((int) $latest, $currency);
                $this->warn("account {$accountId}: SUM {$sumMajor} != latest balance_after {$latestMajor}");
                $issues++;
            }
        }

        if ($issues === 0) {
            $this->info('Ledger reconciled: no drift.');

            return self::SUCCESS;
        }

        $this->error("Ledger drift: {$issues} issue(s) found.");

        return self::FAILURE;
    }
}
