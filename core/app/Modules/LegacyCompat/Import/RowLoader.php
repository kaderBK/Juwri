<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Applies a {@see TableMap} to source rows and writes them to the target with their source ids (plan §1.1 rule 1),
 * recording each written row in `legacy_import_rows` with a checksum of the source row (rule 9).
 *
 * Plain query-builder inserts: no model events, observers or outbox (plan §4: imported rows are inserted once, nothing
 * reacts to them). The caller owns the transaction.
 */
final class RowLoader
{
    public const SIDE_TABLE = 'legacy_import_rows';

    /** @var array<string, array<string, string>> target table => column => type name */
    private array $targetColumns = [];

    /** @var array<string, true> source tables whose column report was already written */
    private array $columnsReported = [];

    public function __construct(private readonly ImportContext $ctx, private readonly string $runId) {}

    /**
     * Map and write $rows. Returns the source ids written.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public function load(TableMap $map, array $rows): array
    {
        if ($map->rowFilter !== null) {
            // A sibling map of the same source table takes the other rows.
            $rows = array_values(array_filter($rows, $map->rowFilter));
        }
        $this->reportColumns($map);
        $report = $this->ctx->report;
        $report->source($map->target, count($rows));
        if ($rows === []) {
            return [];
        }

        $targetCols = $this->targetColumns($map->target);
        // Marks queued by a transform belong to rows of THIS load (a failed earlier load rolled its rows back).
        $this->ctx->discardMarks();
        $out = [];
        $records = [];
        $later = [];
        $written = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ($id === null || ! is_numeric($id) || (int) $id <= 0) {
                $report->skipped($map->target, 'source row without a numeric id', $map->source);

                continue;
            }
            $id = (int) $id;
            $mapped = $this->mapRow($map, $row, $id);
            if (is_string($mapped)) {
                $report->skipped($map->target, $mapped, $map->source.'#'.$id);

                continue;
            }
            if ($map->selfParent !== null && ($mapped[$map->selfParent] ?? null) !== null) {
                $later[$id] = $mapped[$map->selfParent];
                $mapped[$map->selfParent] = null;
            }
            $targetId = $id;
            if ($map->freshIds) {
                // A new id above every kept id of the target (two source tables share it): the side table maps them.
                $targetId = $this->ctx->nextId($map->target);
                $mapped['id'] = $targetId;
            }
            $mapped = array_intersect_key($mapped, $targetCols);
            $out[] = $mapped;
            $records[] = $this->record($map, $row, $id, $targetId);
            $written[] = $id;
            $this->ctx->remember($map->target, $targetId);
        }

        // One multi-row insert takes the first row's columns: rows whose mapped columns differ (a NULL source amount is
        // left to the column default) go in separate batches, keyed by their column set.
        $shapes = [];
        foreach ($out as $mapped) {
            ksort($mapped);
            $shapes[implode(',', array_keys($mapped))][] = $mapped;
        }
        foreach ($shapes as $rowsOfShape) {
            foreach (array_chunk($rowsOfShape, 200) as $batch) {
                $this->ctx->target->table($map->target)->insert($batch);
            }
        }
        foreach (array_chunk($records, 200) as $batch) {
            $this->ctx->target->table(self::SIDE_TABLE)->insert($batch);
        }
        $this->ctx->flushMarks();
        // Self references (a sub-category's parent) are set after the whole table is in, and only to a row of the
        // same store; anything else stays NULL and is reported.
        foreach ($later as $id => $parent) {
            if ($this->ctx->resolves($map->target, $parent)) {
                $this->ctx->target->table($map->target)->where('id', $id)->update([$map->selfParent => $parent]);
            } else {
                $report->conflict($map->target.'.'.$map->selfParent.': parent not in the same store, set NULL', $map->source.'#'.$id);
            }
        }
        $report->written($map->target, count($out));
        if ($map->afterLoad !== null && $written !== []) {
            ($map->afterLoad)($this->ctx, $written);
            $this->ctx->flushMarks();
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string the target row, or the reason it is skipped
     */
    private function mapRow(TableMap $map, array $row, int $id): array|string
    {
        $source = $this->ctx->source;
        $out = ['id' => $id] + $map->defaults;

        foreach ($map->columns as $target => $candidates) {
            $col = $source->pick($map->source, $candidates);
            if ($col !== null) {
                $out[$target] = $row[$col] ?? null;
            }
        }
        foreach ($map->bools as $target => $candidates) {
            $col = $source->pick($map->source, $candidates);
            if ($col !== null && ($row[$col] ?? null) !== null) {
                $out[$target] = filter_var($row[$col], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach ($map->dates as $target => $candidates) {
            $col = $source->pick($map->source, $candidates);
            if ($col === null) {
                continue;
            }
            $raw = $row[$col] ?? null;
            $out[$target] = $this->ctx->utc($raw, $source->isInstant($map->source, $col));
            if ($out[$target] === null && $raw !== null && $raw !== '' && ! (is_string($raw) && str_starts_with($raw, '0000-00-00'))) {
                $this->ctx->report->conflict($map->target.'.'.$target.': unparsable date, set NULL', $map->source.'#'.$id);
            }
        }
        foreach ($map->money as $target => $candidates) {
            $col = $source->pick($map->source, $candidates);
            if ($col === null || ($row[$col] ?? null) === null || $row[$col] === '') {
                continue;
            }
            $minor = $this->ctx->minor($row[$col], $this->ctx->storeId === null ? $this->ctx->platformExponent : null);
            if ($minor === null) {
                $this->ctx->report->conflict($map->target.'.'.$target.': amount not numeric or out of range, not imported', $map->source.'#'.$id);

                continue;
            }
            $out[$target] = $minor;
        }

        if ($map->scope !== TableMap::PLATFORM) {
            if ($this->ctx->storeId === null || $this->ctx->accountId === null) {
                throw new RuntimeException("{$map->target}: store row loaded outside a store");
            }
            $out['account_id'] = $this->ctx->accountId;
            $out['store_id'] = $this->ctx->storeId;
            if ($map->currency) {
                $out['currency_code'] = $this->ctx->currency;
            }
        }
        if ($map->scope === TableMap::CHILD && $map->parentColumn !== null && $map->parentTarget !== null) {
            $parent = $row[$map->parentColumn] ?? null;
            if ($parent === null || ! $this->ctx->resolves($map->parentTarget, (int) $parent)) {
                return 'parent row not imported for this store';
            }
            $out[$map->parentColumn] = (int) $parent;
        }

        foreach ($map->requiredRefs as $column => $table) {
            $value = $out[$column] ?? null;
            if ($value === null || ! $this->ctx->resolves($table, (int) $value)) {
                return "{$column} does not name a {$table} row of this store";
            }
        }
        foreach ($map->refs as $column => $table) {
            $value = $out[$column] ?? null;
            if ($value !== null && ($value === '' || (int) $value === 0)) {
                $out[$column] = null; // the source's "none" (0 / '') is NULL here

                continue;
            }
            if ($value !== null && ! $this->ctx->resolves($table, (int) $value)) {
                $out[$column] = null;
                $this->ctx->report->conflict("{$map->target}.{$column}: {$table} row not in this store (or not imported), set NULL", $map->source.'#'.$id);
            }
        }

        if ($map->transform !== null) {
            $result = ($map->transform)($out, $row, $this->ctx);
            if (is_string($result)) {
                return $result;
            }
            $out = $result;
        }

        if (array_key_exists('public_id', $this->targetColumns($map->target)) && ! isset($out['public_id'])) {
            $out['public_id'] = (string) Str::uuid7();
        }
        $types = $this->targetColumns($map->target);
        foreach ($out as $column => $value) {
            if (is_array($value)) {
                $out[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_string($value) && in_array($types[$column] ?? '', ['json', 'jsonb'], true) && ! json_validate($value)) {
                // A JSON target (MySQL/PostgreSQL) rejects invalid text; the source column was free text.
                $out[$column] = null;
                $this->ctx->report->conflict("{$map->target}.{$column}: not valid JSON, set NULL", $map->source.'#'.$id);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function record(TableMap $map, array $row, int $id, int $targetId): array
    {
        return [
            'source_table' => $map->source,
            'source_id' => (string) $id,
            'target_table' => $map->target,
            'target_id' => (string) $targetId,
            'source_checksum' => self::checksum($map->checksumRow === null ? $row : ($map->checksumRow)($row)),
            'run_id' => $this->runId,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Write a row the import DERIVES from source data (no source row of its own: an option of a source option list,
     * the add-on group an item's source add-ons share) with a new id above every kept id, and record it in the side
     * table under a synthetic source key, so resume checks and history guards see it as imported.
     *
     * @param  array<string, mixed>  $row  target row without an id
     * @param  array<string, mixed>  $basis  the source values it derives from (checksummed)
     * @return int the new id
     */
    public static function writeDerived(ImportContext $ctx, string $targetTable, array $row, string $sourceTable, string $sourceKey, array $basis): int
    {
        $id = $ctx->nextId($targetTable);
        $ctx->target->table($targetTable)->insert(['id' => $id] + $row);
        $ctx->target->table(self::SIDE_TABLE)->insert([
            'source_table' => $sourceTable,
            'source_id' => $sourceKey,
            'target_table' => $targetTable,
            'target_id' => (string) $id,
            'source_checksum' => self::checksum($basis),
            'run_id' => $ctx->runId,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
        $ctx->report->written($targetTable);

        return $id;
    }

    /**
     * Order-independent sha256 of a source row (sorted keys, values as text), the same form the cut-over checksum
     * step uses (plan §6.3).
     *
     * @param  array<string, mixed>  $row
     */
    public static function checksum(array $row): string
    {
        ksort($row);
        $lines = [];
        foreach ($row as $key => $value) {
            $lines[] = $key.'='.($value === null ? "\0" : (is_scalar($value) ? (string) $value : (string) json_encode($value)));
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** @return array<string, string> */
    public function targetColumns(string $table): array
    {
        if (! isset($this->targetColumns[$table])) {
            $cols = [];
            foreach ($this->ctx->target->getSchemaBuilder()->getColumns($table) as $column) {
                $cols[(string) $column['name']] = strtolower((string) $column['type_name']);
            }
            $this->targetColumns[$table] = $cols;
        }

        return $this->targetColumns[$table];
    }

    /** Unknown / missing source columns and missing target columns, once per table per run. */
    public function reportColumns(TableMap $map): void
    {
        if (isset($this->columnsReported[$map->source.'>'.$map->target])) {
            return;
        }
        $this->columnsReported[$map->source.'>'.$map->target] = true;
        $source = $this->ctx->source;
        $have = $source->columns($map->source);
        $used = ['id'];
        $missing = [];
        foreach ($map->sourced() as $candidates) {
            $col = $source->pick($map->source, $candidates);
            if ($col === null) {
                $missing[] = implode('|', (array) $candidates);
            } else {
                $used[] = $col;
            }
        }
        $used = array_merge($used, $map->storeColumns, $map->ignore, $map->uses, $map->parentColumn !== null ? [$map->parentColumn] : []);
        $unknown = array_values(array_diff($have, $used));
        $targetCols = $this->targetColumns($map->target);
        $missingTarget = array_values(array_diff(
            array_keys($map->sourced() + $map->defaults),
            array_keys($targetCols),
        ));
        sort($unknown);
        sort($missing);
        sort($missingTarget);
        $this->ctx->report->columns($map->source, $map->target, $unknown, $missing, $missingTarget);
    }
}
