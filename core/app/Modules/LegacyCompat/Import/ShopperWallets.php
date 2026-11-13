<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * Shoppers and their wallet ledgers (J58d: customer money, the same sum-vs-balance rule as loyalty and the owners'
 * wallets). The source keeps no wallet balance column: each ledger row carries the balance after it. Per shopper and
 * currency, in ledger order, the running sum of the amounts must equal every balance snapshot the source recorded
 * (exact, in minor units of that currency). A shopper whose ledger fails the check — or holds a row that cannot be
 * converted (unknown currency, amount or snapshot not numeric) — is BLOCKED: neither the shopper nor any of its rows
 * is imported, the block is reported (ids and the check only), and a later run retries once the source is corrected.
 *
 * Each shopper is written in ONE transaction with its whole ledger, re-checked on the written rows before it commits
 * (a row dropped on the way would change the balance): a shopper lands with its money complete or not at all, and its
 * side-table record is the resume marker. Balances are never recomputed into the target and never "fixed".
 */
final class ShopperWallets
{
    public const REASON = 'shopper wallet ledger does not add up to its balance snapshots';

    public function __construct(private readonly ImportContext $ctx, private readonly RowLoader $loader) {}

    /**
     * Source shoppers whose wallet ledger cannot be imported as it stands: shopper id => reason.
     *
     * @return array<int, string>
     */
    public function sourceMismatches(): array
    {
        $source = $this->ctx->source;
        $columns = $source->columns(MarketplaceMaps::WALLET);
        if (! in_array('shopper_id', $columns, true) || ! in_array('amount', $columns, true)) {
            return [];
        }
        $hasCurrency = in_array('currency', $columns, true);
        $hasAfter = in_array('balance_after', $columns, true);
        if (! $hasAfter) {
            $this->ctx->report->count('shopper_wallet_no_balance_snapshots');
        }
        /** @var array<int, array<string, int>> $running shopper => currency => running sum (minor) */
        $running = [];
        $bad = [];
        // The whole ledger in id order (the order the source appended it in).
        $source->chunk(MarketplaceMaps::WALLET, function (array $rows) use (&$running, &$bad, $hasCurrency, $hasAfter): void {
            foreach ($rows as $row) {
                $shopper = (int) ($row['shopper_id'] ?? 0);
                if (isset($bad[$shopper])) {
                    continue;
                }
                $currency = $hasCurrency ? strtoupper(trim((string) ($row['currency'] ?? ''))) : '';
                $exponent = $this->ctx->exponentOf($currency);
                if ($exponent === null) {
                    $bad[$shopper] = 'shopper wallet row in a currency unknown to this install';

                    continue;
                }
                $amount = LegacyMoney::toMinor($row['amount'] ?? null, $exponent);
                if ($amount === null) {
                    $bad[$shopper] = 'shopper wallet amount not numeric';

                    continue;
                }
                $sum = ($running[$shopper][$currency] ?? 0) + $amount;
                $running[$shopper][$currency] = $sum;
                $after = $hasAfter ? ($row['balance_after'] ?? null) : null;
                if ($after === null || $after === '') {
                    continue;
                }
                $afterMinor = LegacyMoney::toMinor($after, $exponent);
                if ($afterMinor === null) {
                    $bad[$shopper] = 'shopper wallet balance snapshot not numeric';
                } elseif ($afterMinor !== $sum) {
                    $bad[$shopper] = self::REASON;
                }
            }
        });

        return $bad;
    }

    /**
     * Shoppers an earlier run imported whose source ledger has a different number of rows than was written (the source
     * changed after the import): shopper id => [source rows, written rows]. The imported rows are never touched.
     *
     * @param  list<int>  $shopperIds
     * @return array<int, array{0: int, 1: int}>
     */
    public function changedSinceImport(array $shopperIds): array
    {
        if ($shopperIds === [] || ! in_array('shopper_id', $this->ctx->source->columns(MarketplaceMaps::WALLET), true)) {
            return [];
        }
        $source = $this->ctx->source->db->table(MarketplaceMaps::WALLET)->whereIn('shopper_id', $shopperIds)
            ->selectRaw('shopper_id, count(*) as n')->groupBy('shopper_id')->pluck('n', 'shopper_id')->all();
        $written = $this->ctx->target->table('shopper_wallet_ledger_entries')->whereIn('shopper_id', $shopperIds)
            ->selectRaw('shopper_id, count(*) as n')->groupBy('shopper_id')->pluck('n', 'shopper_id')->all();
        $changed = [];
        foreach ($shopperIds as $id) {
            $a = (int) ($source[$id] ?? 0);
            $b = (int) ($written[$id] ?? 0);
            if ($a !== $b) {
                $changed[$id] = [$a, $b];
            }
        }

        return $changed;
    }

    /**
     * A shopper its own map skipped (country or reseller not on this install) leaves its wallet money behind: the
     * balance per currency (minor units) is reported so it is visible at cut-over.
     *
     * @param  list<array<string, mixed>>  $ledger
     */
    private function reportMoneyLeftBehind(int $id, array $ledger): void
    {
        $sums = [];
        foreach ($ledger as $entry) {
            $currency = strtoupper(trim((string) ($entry['currency'] ?? '')));
            $exponent = $this->ctx->exponentOf($currency);
            $minor = $exponent === null ? null : LegacyMoney::toMinor($entry['amount'] ?? null, $exponent);
            if ($minor !== null) {
                $sums[$currency] = ($sums[$currency] ?? 0) + $minor;
            }
        }
        if ($sums === []) {
            return;
        }
        ksort($sums);
        $parts = [];
        foreach ($sums as $currency => $sum) {
            $parts[] = $currency.':'.$sum;
            $this->ctx->report->count('shopper_wallet_not_imported_minor:'.$currency, $sum);
        }
        $this->ctx->report->conflict('shoppers: wallet balance of a shopper not imported (minor units per currency)', 'shopper#'.$id.' '.implode(' ', $parts));
    }

    /**
     * Import one shopper and its whole ledger in one transaction (the caller's), then re-check the written rows.
     *
     * @param  array<string, mixed>  $row  the source shopper row
     * @return bool whether the shopper was written (false: skipped by its map, reported there)
     *
     * @throws StoreBlocked when the written ledger does not match the source ledger (rolls the shopper back)
     */
    public function importOne(array $row): bool
    {
        $id = (int) ($row['id'] ?? 0);
        $ledger = $this->ctx->source->where(MarketplaceMaps::WALLET, 'shopper_id', $id);
        if ($this->loader->load(MarketplaceMaps::shoppers(), [$row]) === []) {
            $this->reportMoneyLeftBehind($id, $ledger);

            return false;
        }
        if ($ledger === []) {
            return true;
        }
        $written = $this->loader->load(MarketplaceMaps::walletEntries(), $ledger);
        $expected = [];
        foreach ($ledger as $entry) {
            $currency = strtoupper(trim((string) ($entry['currency'] ?? '')));
            $exponent = $this->ctx->exponentOf($currency);
            if ($exponent === null) {
                throw new StoreBlocked('shopper wallet row in a currency unknown to this install');
            }
            $expected[$currency] = ($expected[$currency] ?? 0) + (int) LegacyMoney::toMinor($entry['amount'] ?? null, $exponent);
        }
        $actual = [];
        foreach ($this->ctx->target->table('shopper_wallet_ledger_entries')->where('shopper_id', $id)->get(['currency', 'amount_minor']) as $entry) {
            $actual[(string) $entry->currency] = ($actual[(string) $entry->currency] ?? 0) + (int) $entry->amount_minor;
        }
        ksort($expected);
        ksort($actual);
        if (count($written) !== count($ledger) || $expected !== $actual) {
            throw new StoreBlocked(self::REASON.' after import (rows written '.count($written).' of '.count($ledger).')');
        }
        foreach ($expected as $currency => $sum) {
            $this->ctx->report->count('shopper_wallet_balance_minor:'.$currency, $sum);
        }

        return true;
    }
}
