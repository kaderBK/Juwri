<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

/**
 * State of one import run: the source reader, the target connection, the derived accounts (owner user id => account
 * id), the store being imported (id, account, currency) and the target ids written for it, so references are checked
 * against rows of the SAME store only (tenant isolation).
 */
final class ImportContext
{
    /** @var array<int, int> owner user id => account id */
    public array $accounts = [];

    /** @var array<string, array<string, true>> target table => id => true, for platform rows (users, allergies) */
    public array $platformIds = [];

    /** @var array<string, array<string, true>> target table => id => true, for the current store */
    public array $storeIds = [];

    public ?int $storeId = null;

    public ?int $accountId = null;

    public ?string $currency = null;

    public int $exponent = 2;

    public ?string $platformCurrency = null;

    public int $platformExponent = 2;

    /** @var array<string, int> bare host (no `www.`) => store id, store domains claimed so far by this run */
    public array $claimedHosts = [];

    /** @var array<string, int> bare host claimed by several source stores => the only store id allowed (0 = none) */
    public array $domainWinners = [];

    /** @var array<string, int> bare host => source reseller id, the source's reseller hosts (never a store's) */
    public array $resellerHosts = [];

    /**
     * @var array<string, int> bare host => source reseller id, hosts a reseller only REQUESTED at the source (not
     *                         approved): protected, but a store domain the source verified for a store keeps them
     */
    public array $resellerRequestedHosts = [];

    /** @var array<int, string> source user id => why the user (and the stores they own) is blocked this run */
    public array $blockedUsers = [];

    /** @var array<string, array<int, string>> source billing table => row id => why it is blocked (duplicate key) */
    public array $blockedRows = [];

    /** @var array<string, mixed> the current store's source options (name => raw value) */
    public array $storeOptions = [];

    /** @var list<array{0: string, 1: int, 2: string, 3: array<string, mixed>|null}> marks not written yet */
    private array $marks = [];

    private ?PriceBook $prices = null;

    /** @var array<int, array{0: string, 1: int}> order id => [currency, exponent] of the current store's orders in ANOTHER currency (J58a) */
    public array $orderCurrencies = [];

    /** @var array<string, int> "table:mark" => marks written for the current store */
    public array $storeMarks = [];

    /**
     * @var array<string, int> target table => highest source id any map keeps in it (set by the importer): new ids
     *                         ({@see nextId()}) start above it, so they never take an id a later store keeps
     */
    public array $idFloors = [];

    /** @var array<string, int> target table => next new id of this run */
    private array $nextIds = [];

    /** @var array<string, int> the current store's derived add-on groups: "item:<id>" / "library:<0|1>" => group id */
    public array $addonGroups = [];

    /** @var array<int, array<string, mixed>> source option group id => its source row, until its options are written */
    public array $pendingOptionGroups = [];

    /** @var array<int, string> source shopper id => why the shopper (with its wallet ledger) is blocked this run */
    public array $blockedShoppers = [];

    /** @var array<string, int|null> run-wide lookups of source references (e.g. "country:<source id>" => target id) */
    public array $lookups = [];

    public function __construct(
        public readonly SourceReader $source,
        public readonly Connection $target,
        public readonly ImportReport $report,
        public readonly string $sourceTimezone,
        public readonly string $runId = '',
    ) {}

    public function enterStore(int $storeId, int $accountId, string $currency, int $exponent): void
    {
        $this->storeId = $storeId;
        $this->accountId = $accountId;
        $this->currency = $currency;
        $this->exponent = $exponent;
        $this->storeIds = [];
        $this->prices = null;
        $this->marks = [];
        $this->storeMarks = [];
        $this->storeOptions = [];
        $this->orderCurrencies = [];
        $this->addonGroups = [];
        $this->pendingOptionGroups = [];
    }

    public function leaveStore(): void
    {
        $this->storeOptions = [];
        $this->storeId = null;
        $this->accountId = null;
        $this->currency = null;
        $this->storeIds = [];
        $this->prices = null;
        $this->marks = [];
        $this->orderCurrencies = [];
        $this->addonGroups = [];
        $this->pendingOptionGroups = [];
    }

    /**
     * A new id for a row of $table that has no source id of its own: above every id the import keeps in that table
     * (any source table's highest id) and every row already there. Rows of a rolled-back store leave a gap, never a
     * reused id.
     */
    public function nextId(string $table): int
    {
        if (! isset($this->nextIds[$table])) {
            $this->nextIds[$table] = max($this->idFloors[$table] ?? 0, (int) $this->target->table($table)->max('id')) + 1;
        }

        return $this->nextIds[$table]++;
    }

    /**
     * Currency and exponent an amount of $orderId is in: the order's own when it was imported in another currency than
     * the store's (J58a), else the store's.
     *
     * @return array{0: string, 1: int}
     */
    public function orderCurrency(int $orderId): array
    {
        return $this->orderCurrencies[$orderId] ?? [(string) $this->currency, $this->exponent];
    }

    /** The current store's source prices (loaded on first use, this store's rows only). */
    public function prices(): PriceBook
    {
        if ($this->storeId === null) {
            throw new \RuntimeException('price lookup outside a store');
        }

        return $this->prices ??= new PriceBook($this->source, $this->storeId);
    }

    /**
     * Queue a money/history mark for an imported row ({@see HistoryImporter} kinds); written by {@see flushMarks()}
     * in the same transaction as the row.
     *
     * @param  array<string, mixed>|null  $detail  ids, bases and minor amounts only (never personal data)
     */
    public function mark(string $targetTable, int $id, string $mark, ?array $detail = null): void
    {
        $this->marks[] = [$targetTable, $id, $mark, $detail];
    }

    /** Drop queued marks (the rows they name were never written). */
    public function discardMarks(): void
    {
        $this->marks = [];
    }

    public function flushMarks(): void
    {
        if ($this->marks === []) {
            return;
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->marks as [$table, $id, $mark, $detail]) {
            $rows[] = [
                'target_table' => $table,
                'target_id' => (string) $id,
                'mark' => $mark,
                'detail' => $detail === null ? null : (string) json_encode($detail, JSON_UNESCAPED_SLASHES),
                'run_id' => $this->runId,
                'created_at' => $now,
            ];
            $this->report->count('mark:'.$table.':'.$mark);
            if ($this->storeId !== null) {
                $this->storeMarks[$table.':'.$mark] = ($this->storeMarks[$table.':'.$mark] ?? 0) + 1;
            }
        }
        $this->marks = [];
        foreach (array_chunk($rows, 200) as $batch) {
            $this->target->table(HistoryImporter::MARKS_TABLE)->insert($batch);
        }
    }

    public function remember(string $targetTable, int|string $id): void
    {
        if ($this->storeId === null) {
            $this->platformIds[$targetTable][(string) $id] = true;
        } else {
            $this->storeIds[$targetTable][(string) $id] = true;
        }
    }

    /** Forget a row remembered by a load that was rolled back (it no longer resolves). */
    public function forget(string $targetTable, int|string $id): void
    {
        unset($this->storeIds[$targetTable][(string) $id], $this->platformIds[$targetTable][(string) $id]);
    }

    /** True when $id is a row of $targetTable written for the current store (or a platform row written this run). */
    public function resolves(string $targetTable, int|string $id): bool
    {
        return isset($this->storeIds[$targetTable][(string) $id]) || isset($this->platformIds[$targetTable][(string) $id]);
    }

    /** True only for a row of $targetTable written for the CURRENT store (never a platform row). */
    public function resolvesInStore(string $targetTable, int|string $id): bool
    {
        return $this->storeId !== null && isset($this->storeIds[$targetTable][(string) $id]);
    }

    /** True only for a platform row (no store) of $targetTable, written this run or by an earlier one. */
    public function resolvesPlatform(string $targetTable, int|string $id): bool
    {
        return isset($this->platformIds[$targetTable][(string) $id]);
    }

    /** @return list<int> */
    public function storeIdsOf(string $targetTable): array
    {
        return array_map('intval', array_keys($this->storeIds[$targetTable] ?? []));
    }

    /**
     * A source date-time as a UTC string; null for empty / zero dates. A DATETIME (no zone) is a wall time of the source
     * zone; an $instant column (TIMESTAMP, read in the UTC session) is already UTC.
     */
    public function utc(mixed $value, bool $instant = false): ?string
    {
        if ($value === null || $value === '' || (is_string($value) && str_starts_with($value, '0000-00-00'))) {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value, $instant ? 'UTC' : $this->sourceTimezone)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    public function minor(mixed $amount, ?int $exponent = null): ?int
    {
        return LegacyMoney::toMinor($amount, $exponent ?? $this->exponent);
    }

    /** Exponent of a currency code from this install's `currencies` table, or null when unknown. */
    public function exponentOf(?string $code): ?int
    {
        $code = strtoupper(trim((string) $code));
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return null;
        }
        $exponent = $this->target->table('currencies')->where('code', $code)->value('exponent');

        return $exponent === null ? null : (int) $exponent;
    }
}
