<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting\Gl;

use App\Support\StoreClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Money;
use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Models\GlJournalLine;

/**
 * SYSTEMS-RUN S0: the GL posting engine. Mirrors LedgerService's discipline —
 * balanced legs, idempotent refs — but for the reporting ledger (typed accounts,
 * debit/credit columns). NOT a money path: it classifies events the real money
 * spine (LedgerService/Transaction/Order) has already settled.
 *
 * A line is: ['account' => GlAccount|int(id), 'debit' => float, 'credit' => float, 'memo' => ?string]
 * Exactly one of debit/credit is non-zero per line. Σdebit must equal Σcredit.
 */
class JournalEngine
{
    /**
     * Post a balanced journal. Idempotent per
     * (store_id, source_type, source_id, rule_key): a repeat returns the
     * existing journal untouched. Refuses to post into a locked period.
     *
     * @param  array<int,array{account:GlAccount|int,debit?:float,credit?:float,memo?:string}>  $lines
     */
    public static function post(
        int $storeId,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $ruleKey = null,
        ?string $memo = null,
        $date = null,
        ?int $branchId = null,
        ?int $postedBy = null
    ): GlJournal {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('A GL journal needs at least two lines.');
        }

        // P1-05: every leg in minor units of the store's currency (HALF_UP to its exponent, PHP-version
        // independent), so the balance check is exact integer arithmetic per currency.
        $currency = strtoupper(store_currency($storeId));
        $legs = [];
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $i => $line) {
            $legs[$i] = [
                Money::minorFromMajor((float) ($line['debit'] ?? 0), $currency),
                Money::minorFromMajor((float) ($line['credit'] ?? 0), $currency),
            ];
            $totalDebit += $legs[$i][0];
            $totalCredit += $legs[$i][1];
        }
        if ($totalDebit !== $totalCredit) {
            $d = Money::ofMinor($totalDebit, $currency)->major();
            $c = Money::ofMinor($totalCredit, $currency)->major();
            throw new \InvalidArgumentException(
                "GL journal must balance (debit {$d} != credit {$c})."
            );
        }
        if ($totalDebit <= 0) {
            throw new \InvalidArgumentException('GL journal has no amount.');
        }

        $date = $date ? Carbon::parse($date)->toDateString() : StoreClock::today($storeId);

        // Locked-period guard (period close, A-P3).
        if (PeriodLock::isLocked($storeId, $date)) {
            throw new \RuntimeException("Accounting period for {$date} is locked.");
        }

        return DB::transaction(function () use (
            $storeId, $lines, $legs, $currency, $sourceType, $sourceId, $ruleKey, $memo, $date, $branchId, $postedBy
        ) {
            // Idempotency on the source dimension.
            if ($sourceType !== null && $sourceId !== null) {
                $existing = GlJournal::where('store_id', $storeId)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('rule_key', $ruleKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $journal = GlJournal::create([
                'store_id' => $storeId,
                'branch_id' => $branchId,
                'journal_no' => self::nextJournalNo($storeId),
                'date' => $date,
                'memo' => $memo,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'rule_key' => $ruleKey,
                'posted_by' => $postedBy,
                'locked' => false,
            ]);

            foreach ($lines as $i => $line) {
                $accountId = $line['account'] instanceof GlAccount
                    ? $line['account']->id
                    : (int) $line['account'];
                GlJournalLine::create([
                    'journal_id' => $journal->id,
                    'gl_account_id' => $accountId,
                    'currency_code' => $currency,
                    'debit_minor' => $legs[$i][0],
                    'credit_minor' => $legs[$i][1],
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $journal->load('lines');
        });
    }

    /** Resolve a system account by code, seeding the chart first if needed. */
    public static function account(int $storeId, string $code): GlAccount
    {
        ChartOfAccounts::ensure($storeId);
        $acct = GlAccount::where('store_id', $storeId)->where('code', $code)->first();
        if (! $acct) {
            throw new \RuntimeException("GL account {$code} not found for restaurant {$storeId}.");
        }

        return $acct;
    }

    private static function nextJournalNo(int $storeId): string
    {
        $n = GlJournal::where('store_id', $storeId)->count() + 1;

        return 'J'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
