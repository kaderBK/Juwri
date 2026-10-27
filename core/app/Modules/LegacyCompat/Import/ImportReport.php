<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * What one `legacy:import` run did (or, on a dry run, would do): per-table source/written/skipped counts, conflicts
 * (rows not imported or references dropped, with the reason), unknown and missing source columns per table, source
 * tables that have no loader, unmapped store setting names, secrets that could not be imported, store domains to
 * re-verify, the source's reseller hosts and the imported slugs this install's routes shadow. Holds names, ids, hosts
 * and counts only — never a setting value, a secret or personal data.
 */
final class ImportReport
{
    /** Cap per conflict kind so a broken source does not fill memory; the count keeps going. */
    private const SAMPLE_CAP = 200;

    /** @var array<string, array{source: int, written: int, skipped: int}> */
    private array $tables = [];

    /** @var array<string, array{count: int, samples: list<string>}> */
    private array $conflicts = [];

    /** @var array<string, list<string>> source table => unknown source columns */
    private array $unknownColumns = [];

    /** @var array<string, list<string>> source table => mapped source columns the source does not have */
    private array $missingColumns = [];

    /** @var array<string, list<string>> target table => mapped target columns this install does not have */
    private array $missingTargetColumns = [];

    /** @var list<string> */
    private array $unmappedTables = [];

    /** @var array<string, int> setting name => stores that had it */
    private array $unmappedSettings = [];

    /** @var array<string, int> dropped setting name => stores that had it */
    private array $droppedSettings = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, int|string> */
    private array $sequences = [];

    /** @var list<array{store_id: int, host: string, type: string}> store domains imported unverified (must re-verify) */
    private array $domainsToVerify = [];

    /** @var list<array{reseller_id: int, host: string, active: bool, status: string}> */
    private array $resellerHosts = [];

    /** @var list<array{store_id: int, slug: string, reason: string}> */
    private array $slugConflicts = [];

    /** @var array<string, array<string, int|string>> store id => totals for the cut-over checksum step */
    private array $storeTotals = [];

    /** @var array<string, scalar|null> how the source was read (table prefix, zones) */
    private array $source = [];

    /** @var array<string, string> source table => why it is skipped by decision (owner decisions, plan §1.3) */
    private array $skippedByDecision = [];

    /** @var array<string, list<string>> store id => gateways imported switched off (J59e) */
    private array $gatewaysOff = [];

    /** @var array<string, array<string, mixed>> owner decision item (J59a…k) => what this run did about it */
    private array $decisions = [];

    public function __construct(public readonly string $runId, public readonly bool $dryRun) {}

    /** One fact about how the source was read (never a credential). */
    public function sourceInfo(string $key, string|int|bool|null $value): void
    {
        $this->source[$key] = $value;
    }

    /** A source table deliberately not imported, with the decision behind it (listed in the report). */
    public function skippedByDecision(string $table, string $why): void
    {
        $this->skippedByDecision[$table] = $why;
    }

    public function source(string $table, int $count = 1): void
    {
        $this->tables[$table] ??= ['source' => 0, 'written' => 0, 'skipped' => 0];
        $this->tables[$table]['source'] += $count;
    }

    public function written(string $table, int $count = 1): void
    {
        $this->tables[$table] ??= ['source' => 0, 'written' => 0, 'skipped' => 0];
        $this->tables[$table]['written'] += $count;
    }

    public function skipped(string $table, string $reason, string $sample): void
    {
        $this->tables[$table] ??= ['source' => 0, 'written' => 0, 'skipped' => 0];
        $this->tables[$table]['skipped']++;
        $this->conflict($table.': '.$reason, $sample);
    }

    /**
     * A row (a store, an owner) refused because its money does not reconcile: listed under a `blocked:` kind, which
     * makes the command exit non-zero (the cut-over waits for it).
     */
    public function blocked(string $table, string $reason, string $sample): void
    {
        $this->tables[$table] ??= ['source' => 0, 'written' => 0, 'skipped' => 0];
        $this->tables[$table]['skipped']++;
        $this->conflict('blocked: '.$table.': '.$reason, $sample);
    }

    /**
     * The counting state before a unit of work that may roll back (one store): {@see restore()} puts it back so a
     * rolled-back store leaves no written/skipped counts, conflicts or counters behind (only the reason it failed).
     */
    public function checkpoint(): self
    {
        return clone $this;
    }

    public function restore(self $state): void
    {
        $this->tables = $state->tables;
        $this->conflicts = $state->conflicts;
        $this->counters = $state->counters;
        $this->unmappedSettings = $state->unmappedSettings;
        $this->droppedSettings = $state->droppedSettings;
        $this->domainsToVerify = $state->domainsToVerify; // a rolled-back store's domains were never written
        $this->gatewaysOff = $state->gatewaysOff;
        // Column reports describe the source schema, not rows: what the failed store discovered is kept.
    }

    /** @param array<string, int|string> $totals */
    public function storeTotals(int $storeId, array $totals): void
    {
        $this->storeTotals[(string) $storeId] = $totals;
    }

    /** A conflict that does not skip the row (a reference set to NULL, a value not imported). */
    public function conflict(string $kind, string $sample): void
    {
        $this->conflicts[$kind] ??= ['count' => 0, 'samples' => []];
        $this->conflicts[$kind]['count']++;
        if (count($this->conflicts[$kind]['samples']) < self::SAMPLE_CAP) {
            $this->conflicts[$kind]['samples'][] = $sample;
        }
    }

    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $missing
     * @param  list<string>  $missingTarget
     */
    public function columns(string $sourceTable, string $targetTable, array $unknown, array $missing, array $missingTarget): void
    {
        if ($unknown !== []) {
            $this->unknownColumns[$sourceTable] = $unknown;
        }
        if ($missing !== []) {
            $this->missingColumns[$sourceTable] = $missing;
        }
        if ($missingTarget !== []) {
            $this->missingTargetColumns[$targetTable] = $missingTarget;
        }
    }

    public function unmappedTable(string $table): void
    {
        $this->unmappedTables[] = $table;
    }

    public function unmappedSetting(string $name): void
    {
        $this->unmappedSettings[$name] = ($this->unmappedSettings[$name] ?? 0) + 1;
    }

    public function droppedSetting(string $name): void
    {
        $this->droppedSettings[$name] = ($this->droppedSettings[$name] ?? 0) + 1;
    }

    public function count(string $counter, int $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    public function sequence(string $table, int|string $next): void
    {
        $this->sequences[$table] = $next;
    }

    /** A store domain written unverified: it serves nothing until it passes this install's DNS check (plan §3, R5). */
    public function domainToVerify(int $storeId, string $host, string $type): void
    {
        $this->domainsToVerify[] = ['store_id' => $storeId, 'host' => $host, 'type' => $type];
    }

    /** A source reseller host and what happened to it (the Reseller edition imports resellers; this run only lists them). */
    public function resellerHost(int $resellerId, string $host, bool $active, string $status, string $kind = 'custom_domain'): void
    {
        $this->resellerHosts[] = ['reseller_id' => $resellerId, 'host' => $host, 'active' => $active, 'status' => $status, 'kind' => $kind];
    }

    /** @param list<string> $gateways J59e: gateways of a store imported switched off */
    public function gatewaysSwitchedOff(int $storeId, array $gateways): void
    {
        $this->gatewaysOff[(string) $storeId] = $gateways;
    }

    /**
     * One owner-decision section of the report (J59 item => facts); set once at the end of the run.
     *
     * @param  array<string, mixed>  $facts
     */
    public function decision(string $item, array $facts): void
    {
        $this->decisions[$item] = $facts;
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters;
    }

    /** @return array<string, list<string>> */
    public function gatewaysOff(): array
    {
        return $this->gatewaysOff;
    }

    /** @param  list<array{store_id: int, slug: string, reason: string}>  $conflicts */
    public function slugConflicts(array $conflicts): void
    {
        $this->slugConflicts = $conflicts;
    }

    public function writtenCount(string $table): int
    {
        return $this->tables[$table]['written'] ?? 0;
    }

    public function conflictCount(string $kind): int
    {
        return $this->conflicts[$kind]['count'] ?? 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        ksort($this->tables);
        ksort($this->conflicts);
        ksort($this->unmappedSettings);
        ksort($this->droppedSettings);
        ksort($this->counters);
        ksort($this->skippedByDecision);
        $unmapped = array_values(array_unique($this->unmappedTables));
        sort($unmapped);

        return [
            'run_id' => $this->runId,
            'dry_run' => $this->dryRun,
            'source' => $this->source,
            'tables' => $this->tables,
            'conflicts' => $this->conflicts,
            'unknown_source_columns' => $this->unknownColumns,
            'missing_source_columns' => $this->missingColumns,
            'missing_target_columns' => $this->missingTargetColumns,
            'unmapped_source_tables' => $unmapped,
            'unmapped_settings' => $this->unmappedSettings,
            'dropped_settings' => $this->droppedSettings,
            'counters' => $this->counters,
            'sequences' => $this->sequences,
            'domains_to_verify' => $this->domainsToVerify,
            'reseller_hosts' => $this->resellerHosts,
            'slug_conflicts' => $this->slugConflicts,
            'store_totals' => $this->storeTotals,
            'skipped_by_decision' => $this->skippedByDecision,
            'decisions' => $this->decisions,
        ];
    }
}
