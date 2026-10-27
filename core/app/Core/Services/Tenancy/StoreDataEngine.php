<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Contracts\Tenancy\StoreDataPolicy;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;
use Laravel\Pennant\Feature;
use LogicException;

/**
 * P1-21 (J29-8): runs a store operation from the {@see StoreDataRegistry} — no table list of its own.
 *
 *  - {@see rekeyTables()}: the tables whose store rows follow the store to its new account (Move + Reset; the Store
 *    model's `updated` hook re-keys them through {@see TenantFill::rekeyStore()} when the store's account changes).
 *  - {@see transfer()}: the rest of a same-region transfer, inside the caller's transaction: user columns of the
 *    store re-pointed to the new owner, every Reset table's handler run (the previous owner's gateway credentials,
 *    integration tokens, webhook/push subscriptions, messaging credentials, domain verification).
 *  - {@see duplicate()}: copy mode — every Copy table's rows of the source store copied onto the clone (new bigint
 *    ids; every declared reference remapped onto the copies, in dependency order; fresh unique values; soft-deleted
 *    rows skipped). The store row itself is the caller's (StoreCloneService).
 *
 * Rows of other stores and other accounts are never read or written: every statement is scoped to one store id.
 */
final class StoreDataEngine
{
    /** @var array<string, array<string, array{nullable: bool}>> table => column => meta */
    private array $columns = [];

    /**
     * Store tables (store_id + account_id) whose rows follow the store to its new account. A table missing from the
     * registry still follows (the architecture test keeps the registry complete).
     *
     * @return list<string>
     */
    public static function rekeyTables(): array
    {
        return array_values(array_filter(
            TenantTables::storeAccountTables(),
            fn (string $table) => StoreDataRegistry::get($table)?->transfer !== StoreDataPolicy::StayWithOldAccount,
        ));
    }

    /**
     * Re-point the store's user columns to the new owner and reset the previous owner's connections. Returns what was
     * reset, for the transfer's audit entry. Call inside the transfer's transaction, after the store changed owner.
     *
     * @return array<string, mixed>
     */
    public function transfer(Connection $db, StoreTransferContext $ctx): array
    {
        // Moves rows between accounts: platform work the caller authorised (an accepted or forced transfer).
        return TenantContext::bypass(function () use ($db, $ctx): array {
            foreach (StoreDataRegistry::tables() as $table) {
                // BL-036 round 4: rows whose owner moves are a security change for tables that track one
                // (staff_members.security_changed_at): an email-change undo then switches transferred staff off.
                $stamp = $table->repoint !== [] && $db->getSchemaBuilder()->hasColumn($table->table, 'security_changed_at')
                    ? ['security_changed_at' => now()] : [];
                foreach ($table->repoint as $column) {
                    $db->table($table->table)->where('store_id', $ctx->storeId)->update([$column => $ctx->toUserId] + $stamp);
                }
            }

            $done = [];
            foreach (StoreDataRegistry::where(StoreDataPolicy::Reset) as $table) {
                if ($table->reset === null || ! $db->getSchemaBuilder()->hasTable($table->table)) {
                    continue;
                }
                $key = $table->resetKey ?? $table->table;
                $result = ($table->reset)($db, $ctx);
                if (is_array($result) && array_is_list($result) && is_array($done[$key] ?? null)) {
                    $merged = array_values(array_unique([...$done[$key], ...$result]));
                    sort($merged);
                    $done[$key] = $merged;
                } else {
                    $done[$key] = $result;
                }
            }

            $done['files'] = $this->moveFiles($db, $ctx);

            // After commit: the modules' store caches and Pennant's in-memory flags (store-scoped flags live in
            // stores.settings, which moved with the store row; the Pennant driver persists nothing).
            $db->afterCommit(function () use ($ctx): void {
                foreach (StoreDataRegistry::modules() as $data) {
                    $data->forgetStoreCaches($ctx->storeId);
                }
                Feature::flushCache();
            });

            return $done;
        });
    }

    /**
     * Files of the store under the previous account's prefix (`accounts/<from>/…`) are copied to the new account's
     * prefix and the rows re-pointed; the old copies are deleted after commit (a rollback leaves the originals and the
     * rows untouched — at worst an orphan copy). Files outside the account prefix (older uploads) stay where they are.
     *
     * @return int files moved
     */
    private function moveFiles(Connection $db, StoreTransferContext $ctx): int
    {
        if ($ctx->fromAccountId === null || $ctx->toAccountId === null || $ctx->fromAccountId === $ctx->toAccountId) {
            return 0;
        }
        $from = 'accounts/'.$ctx->fromAccountId.'/';
        $to = 'accounts/'.$ctx->toAccountId.'/';
        $moved = 0;
        $old = [];
        foreach (StoreDataRegistry::tables() as $table) {
            foreach ($table->files as $column => $disk) {
                $rows = $db->table($table->table)->where('store_id', $ctx->storeId)->where($column, 'like', $from.'%')->get(['id', $column]);
                foreach ($rows as $row) {
                    $path = (string) $row->{$column};
                    $target = $to.substr($path, strlen($from));
                    $storage = Storage::disk($disk);
                    if ($storage->exists($path) && ! $storage->exists($target)) {
                        $storage->copy($path, $target);
                    }
                    $db->table($table->table)->where('id', $row->id)->update([$column => $target]);
                    $old[] = [$disk, $path];
                    $moved++;
                }
            }
        }
        if ($old !== []) {
            $db->afterCommit(function () use ($old): void {
                foreach ($old as [$disk, $path]) {
                    Storage::disk($disk)->delete($path);
                }
            });
        }

        return $moved;
    }

    /**
     * Copy every Copy table's rows of $sourceId onto $cloneId. Returns the number of rows copied per table.
     *
     * @return array<string, int>
     */
    public function duplicate(Connection $db, int $sourceId, int $cloneId, ?int $cloneAccountId): array
    {
        /** @var array<string, array<int, int>> $maps table => source id => copy id */
        $maps = ['stores' => [$sourceId => $cloneId]];
        $counts = [];

        foreach ($this->copyOrder() as $table) {
            $counts[$table->table] = $this->copyTable($db, $table, $sourceId, $cloneId, $cloneAccountId, $maps);
        }

        return $counts;
    }

    /**
     * The Copy tables (the store row excluded), each after the tables it references.
     *
     * @return list<StoreTable>
     */
    public function copyOrder(): array
    {
        $pending = array_filter(StoreDataRegistry::where(StoreDataPolicy::Copy), fn (StoreTable $t) => $t->table !== 'stores' && $t->duplicate === StoreDataPolicy::Copy);
        $ordered = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $name => $table) {
                $deps = [];
                foreach (array_keys($table->remap) as $column) {
                    [$target] = $table->remapTarget($column) ?? [null];
                    if ($target !== null && $target !== $name && $target !== 'stores') {
                        $deps[] = $target;
                    }
                }
                foreach ($table->remapKeys as $target) {
                    if ($target !== $name) {
                        $deps[] = $target;
                    }
                }
                if (array_diff($deps, array_keys($ordered)) === []) {
                    $ordered[$name] = $table;
                    unset($pending[$name]);
                    $progress = true;
                }
            }
            if (! $progress) {
                throw new LogicException('store-data copy references form a cycle or point at tables that are not copied: '.implode(', ', array_keys($pending)));
            }
        }

        return array_values($ordered);
    }

    /** @param  array<string, array<int, int>>  $maps */
    private function copyTable(Connection $db, StoreTable $table, int $sourceId, int $cloneId, ?int $cloneAccountId, array &$maps): int
    {
        $name = $table->table;
        $columns = $this->columns($db, $name);
        $maps[$name] = [];
        $selfRefs = [];
        $now = now();

        $query = $db->table($name)->where('store_id', $sourceId)->orderBy('id');
        if (isset($columns['deleted_at'])) {
            $query->whereNull('deleted_at');
        }

        foreach ($query->get() as $source) {
            $row = (array) $source;
            $oldId = (int) $row['id'];
            unset($row['id']);
            $row['store_id'] = $cloneId;
            if (isset($columns['account_id'])) {
                $row['account_id'] = $cloneAccountId;
            }

            $pendingSelf = [];
            foreach (array_keys($table->remap) as $column) {
                [$target, $required] = $table->remapTarget($column) ?? [null, false];
                $old = $row[$column] ?? null;
                if ($target === null || $old === null || $old === '') {
                    continue;
                }
                if ($target === $name) {
                    $pendingSelf[$column] = (int) $old;
                    $row[$column] = null;

                    continue;
                }
                $new = $maps[$target][(int) $old] ?? null;
                if ($new === null && $required) {
                    continue 2; // the row belongs to something that was not copied (an orphan, a trashed parent)
                }
                $row[$column] = $new;
            }
            foreach ($table->remapKeys as $column => $target) {
                $decoded = is_string($row[$column] ?? null) ? json_decode((string) $row[$column], true) : null;
                if (! is_array($decoded) || $decoded === []) {
                    continue;
                }
                $mapped = [];
                foreach ($decoded as $key => $value) {
                    if (! is_numeric($key)) {
                        $mapped[$key] = $value; // a label key ({"Size": "M"}) is not an id
                    } elseif (isset($maps[$target][(int) $key])) {
                        $mapped[(string) $maps[$target][(int) $key]] = $value;
                    }
                }
                $row[$column] = json_encode((object) $mapped);
            }
            foreach ($table->clear as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }
            foreach ($table->values as $column => $value) {
                $row[$column] = $value;
            }
            foreach ($table->fresh as $column => $make) {
                $row[$column] = $make($db);
            }
            foreach (['created_at', 'updated_at'] as $stamp) {
                if (isset($columns[$stamp])) {
                    $row[$stamp] = $now;
                }
            }
            if ($table->transform !== null) {
                $row = ($table->transform)($row);
                if ($row === null) {
                    continue;
                }
            }

            $newId = (int) $db->table($name)->insertGetId($row);
            $maps[$name][$oldId] = $newId;
            if ($pendingSelf !== []) {
                $selfRefs[$newId] = $pendingSelf;
            }
        }

        // Self references (a sub-category's parent) once every row of the table has its copy.
        foreach ($selfRefs as $newId => $refs) {
            $update = [];
            foreach ($refs as $column => $old) {
                $update[$column] = $maps[$name][$old] ?? null;
            }
            $db->table($name)->where('id', $newId)->update($update);
        }

        return count($maps[$name]);
    }

    /** @return array<string, array{nullable: bool}> */
    private function columns(Connection $db, string $table): array
    {
        if (! isset($this->columns[$table])) {
            $this->columns[$table] = [];
            foreach ($db->getSchemaBuilder()->getColumns($table) as $column) {
                $this->columns[$table][$column['name']] = ['nullable' => (bool) $column['nullable']];
            }
        }

        return $this->columns[$table];
    }
}
