<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * Ledger reconciliation (plan §4, §6.3): a balance column is imported only when it equals the sum of its ledger rows.
 * Balances are never recomputed into the target and a mismatch is never "fixed": it blocks the store (loyalty) or the
 * owner with every store they own (the owner's platform wallet), the block is reported, and a later run retries once
 * the source is corrected.
 *
 *  - Loyalty: per source loyalty account, `balance` (integer points/stamps) against the sum of its `loyalty_ledger`
 *    deltas — checked on the source before the store is written, and again on the written rows before it commits.
 *  - Wallet: per source user, `user.balance` against the sum of its `wallet_transactions` amounts, both in minor units
 *    of the platform currency (LegacyMoney). A wallet row in another currency cannot be summed and blocks the user too.
 *    A ledger row without a numeric `balance_after` blocks the user too (the target needs it; dropping the row would
 *    change the balance). Without a wallet ledger table in the source there is nothing to compare: the balance
 *    becomes one opening-balance ledger row ({@see OpeningBalances}).
 */
final class LedgerChecks
{
    public const LOYALTY_ACCOUNTS = 'loyalty_accounts';

    public const LOYALTY_LEDGER = 'loyalty_ledger';

    public const WALLET = 'wallet_transactions';

    public const LOYALTY_REASON = 'loyalty ledger does not sum to the account balance';

    public function __construct(private readonly ImportContext $ctx) {}

    /**
     * Source loyalty accounts of a store whose ledger does not sum to their balance.
     *
     * @return list<int> loyalty account ids
     */
    public function loyaltyMismatches(int $storeId): array
    {
        $source = $this->ctx->source;
        $column = $source->pick(self::LOYALTY_ACCOUNTS, LegacyTableMaps::STORE_KEYS);
        if ($column === null || ! in_array('balance', $source->columns(self::LOYALTY_ACCOUNTS), true)) {
            return [];
        }
        $balances = [];
        $source->chunk(self::LOYALTY_ACCOUNTS, function (array $rows) use (&$balances): void {
            foreach ($rows as $row) {
                $balances[(int) $row['id']] = (int) ($row['balance'] ?? 0);
            }
        }, $column, [$storeId]);
        if ($balances === []) {
            return [];
        }
        $sums = array_fill_keys(array_keys($balances), 0);
        $ledger = $source->columns(self::LOYALTY_LEDGER);
        if (in_array('loyalty_account_id', $ledger, true) && in_array('delta', $ledger, true)) {
            $source->chunk(self::LOYALTY_LEDGER, function (array $rows) use (&$sums): void {
                foreach ($rows as $row) {
                    $sums[(int) $row['loyalty_account_id']] += (int) ($row['delta'] ?? 0);
                }
            }, 'loyalty_account_id', array_keys($balances));
        }
        $bad = [];
        foreach ($balances as $id => $balance) {
            if ($sums[$id] !== $balance) {
                $bad[] = $id;
            }
        }

        return $bad;
    }

    /**
     * The written loyalty accounts of the store against their written ledger rows (rows dropped on the way would show
     * here).
     *
     * @throws StoreBlocked
     */
    public function assertImportedLoyalty(int $storeId): void
    {
        $accounts = $this->ctx->storeIdsOf('loyalty_accounts');
        if ($accounts === []) {
            return;
        }
        $target = $this->ctx->target;
        $sums = [];
        foreach ($target->table('loyalty_ledger_entries')->where('store_id', $storeId)->get(['loyalty_account_id', 'delta']) as $row) {
            $sums[(int) $row->loyalty_account_id] = ($sums[(int) $row->loyalty_account_id] ?? 0) + (int) $row->delta;
        }
        foreach ($target->table('loyalty_accounts')->where('store_id', $storeId)->get(['id', 'balance']) as $account) {
            if (($sums[(int) $account->id] ?? 0) !== (int) $account->balance) {
                throw new StoreBlocked(self::LOYALTY_REASON.' after import (loyalty_account#'.(int) $account->id.')');
            }
        }
    }

    /**
     * Source users whose platform wallet balance does not equal the sum of their wallet ledger (or whose ledger cannot
     * be summed): user id => reason. Empty when the source has no wallet ledger or the platform currency is unknown.
     *
     * @return array<int, string>
     */
    public function walletMismatches(): array
    {
        $source = $this->ctx->source;
        $exponent = $this->ctx->platformExponent;
        if ($this->ctx->platformCurrency === null || ! $source->has(LegacyTableMaps::USERS)) {
            return [];
        }
        $ledger = $source->columns(self::WALLET);
        if (! in_array('user_id', $ledger, true) || ! in_array('amount', $ledger, true)) {
            $this->ctx->report->count('wallet_ledger_absent');

            return [];
        }
        $hasCurrency = in_array('currency_code', $ledger, true);
        $hasAfter = in_array('balance_after', $ledger, true);
        /** @var array<int, int> $sums */
        $sums = [];
        $bad = [];
        $source->chunk(self::WALLET, function (array $rows) use (&$sums, &$bad, $exponent, $hasCurrency, $hasAfter): void {
            foreach ($rows as $row) {
                $user = (int) ($row['user_id'] ?? 0);
                $currency = $hasCurrency ? strtoupper(trim((string) ($row['currency_code'] ?? ''))) : '';
                if ($currency !== '' && $currency !== $this->ctx->platformCurrency) {
                    $bad[$user] = 'wallet ledger row in another currency than the platform currency';

                    continue;
                }
                $minor = LegacyMoney::toMinor($row['amount'] ?? null, $exponent);
                if ($minor === null) {
                    $bad[$user] = 'wallet ledger amount not numeric';

                    continue;
                }
                // The target keeps a balance snapshot on every row (NOT NULL): a row without one cannot be imported,
                // and leaving it out would change the spendable balance.
                if (! $hasAfter || LegacyMoney::toMinor($row['balance_after'] ?? null, $exponent) === null) {
                    $bad[$user] = 'wallet ledger row without a numeric balance_after';

                    continue;
                }
                $sums[$user] = ($sums[$user] ?? 0) + $minor;
            }
        });
        if (! in_array('balance', $source->columns(LegacyTableMaps::USERS), true)) {
            return $bad;
        }
        $source->chunk(LegacyTableMaps::USERS, function (array $rows) use (&$sums, &$bad, $exponent): void {
            foreach ($rows as $row) {
                $user = (int) $row['id'];
                if (isset($bad[$user])) {
                    continue;
                }
                $balance = ($row['balance'] ?? null) === null || $row['balance'] === '' ? 0 : LegacyMoney::toMinor($row['balance'], $exponent);
                if ($balance === null) {
                    $bad[$user] = 'wallet balance not numeric';
                } elseif ($balance !== ($sums[$user] ?? 0)) {
                    $bad[$user] = 'wallet ledger does not sum to the user balance';
                }
            }
        });

        return $bad;
    }
}
