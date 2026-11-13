<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use RuntimeException;
use ZipArchive;

/**
 * P1-21 part 2 (J29-5): restore ONE account from an export bundle ({@see StoreDataExporter}) into a database that does
 * not hold it (a fresh install, or after the account was deleted).
 *
 * P6-92: a STORE bundle restores that one store into its EXISTING (live) account — {@see import()} with
 * $intoAccountId. Same account only: the bundle's store row must name that account and its owner, and the bundle must
 * carry this installation's signature ({@see StoreDataExporter::verify()}: no edited row, no added or removed entry).
 * Ids are kept, exactly like an account restore — the store comes back as it was exported. Decision: no id remap.
 * A remap is only declared for catalogue tables (duplicate); orders, invoices (ZATCA hash chain), payments, ledgers
 * hold references the registry does not describe, so a remapped restore could silently point rows at the wrong
 * parent. The restore therefore REFUSES when the store id or any bundle row id still exists (delete the store first,
 * or restore into a scratch database and use the store duplicate). File paths (`accounts/<id>/…`) and media dirs
 * (`{store_id}`) stay valid because account and store ids are unchanged. `$dryRun` runs every check and the inserts,
 * then rolls back (no files written).
 *
 *  - Ids are kept as exported (the registry declares no id change for a restore), so every reference stays valid;
 *    the restore refuses when the account or any exported row id already exists.
 *  - Tables are written parents first ({@see StoreDataScope::orderedTables()}); self references (a sub-category's
 *    parent) are set after the table. Secret columns get their declared placeholder or NULL — the owner resets the
 *    password and reconnects gateways/integrations; tables never exported (credentials) stay empty.
 *  - Columns the current schema no longer has are dropped (reported); PostgreSQL id sequences are moved past the rows.
 *  - One transaction; the bundle's files and media are written after commit, never over an existing file.
 *
 * Platform reference data the rows point at (plans, currencies, countries, cities, cuisines) must exist on the target.
 */
final class StoreDataImporter
{
    /** @return array{scope: string, tables: array<string, int>, dropped_columns: array<string, list<string>>, files: int, dry_run: bool} */
    public function import(Connection $db, string $zipPath, ?int $actorUserId = null, string $actor = 'console', ?int $intoAccountId = null, bool $dryRun = false): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("cannot open {$zipPath}");
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== StoreDataExporter::FORMAT || (int) ($manifest['version'] ?? 0) !== StoreDataExporter::VERSION) {
                throw new RuntimeException('not a store-data bundle (or an unsupported version)');
            }
            $type = $manifest['scope']['type'] ?? null;
            if ($type === 'account') {
                if ($intoAccountId !== null) {
                    throw new RuntimeException('an account bundle restores as its own account — no target account');
                }
                $scope = StoreDataScope::account((int) $manifest['scope']['id']);
                $accountId = $scope->id;
            } elseif ($type === 'store') {
                if ($intoAccountId === null) {
                    throw new RuntimeException('a store bundle restores into an existing account — pass the account');
                }
                StoreDataExporter::verify($zip);
                $scope = StoreDataScope::store((int) $manifest['scope']['id']);
                $accountId = $intoAccountId;
            } else {
                throw new RuntimeException('unknown bundle scope');
            }

            return TenantContext::bypass(fn (): array => $this->restore($db, $zip, $manifest, $scope, $accountId, $actorUserId, $actor, $dryRun));
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{scope: string, tables: array<string, int>, dropped_columns: array<string, list<string>>, files: int, dry_run: bool}
     */
    private function restore(Connection $db, ZipArchive $zip, array $manifest, StoreDataScope $scope, int $accountId, ?int $actorUserId, string $actor, bool $dryRun): array
    {
        $isStore = $scope->type === 'store';
        $order = array_column(array_map(fn (StoreTable $t) => [$t->table, $t], $scope->orderedTables($db)), 1, 0);
        if (! $isStore) {
            $order = array_merge(['accounts' => null], $order);
        }
        foreach (array_keys($manifest['tables'] ?? []) as $name) {
            if (! array_key_exists($name, $order)) {
                throw new RuntimeException("bundle table {$name} is not in the store-data registry for a {$scope->type} restore");
            }
        }
        if ($isStore) {
            $this->assertStoreFitsAccount($db, $zip, $manifest, $scope->id, $accountId);
        } elseif ($db->table('accounts')->where('id', $accountId)->exists()) {
            throw new RuntimeException("account {$accountId} already exists — delete it first or restore into another database");
        }

        // Edition limits: the account's stores (each with its branches) must fit this installation's store/branch
        // limits — the account is new here, so they are checked as a set from zero. Nothing is written on a refusal.
        $branchesOf = [];
        foreach (isset($manifest['tables']['branches']) ? $this->rows($zip, 'branches') : [] as $branch) {
            $branchesOf[(int) ($branch['store_id'] ?? 0)] = ($branchesOf[(int) ($branch['store_id'] ?? 0)] ?? 0) + 1;
        }
        app(StoreLimitGuard::class)->assertStoreSet($isStore ? $accountId : null, array_map(fn (array $store): array => [
            'type' => isset($store['store_type']) && $store['store_type'] !== '' ? (string) $store['store_type'] : null,
            'branches' => $branchesOf[(int) ($store['id'] ?? 0)] ?? 1,
        ], isset($manifest['tables']['stores']) ? $this->rows($zip, 'stores') : []));

        $schema = $db->getSchemaBuilder();
        $counts = [];
        $dropped = [];

        $stores = $isStore ? [$scope->id] : array_map('intval', (array) ($manifest['stores'] ?? []));
        $users = $isStore ? [] : array_map('intval', (array) ($manifest['users'] ?? []));
        $restoredIds = [];

        $dryRunDone = new RuntimeException('dry run');
        try {
        $db->transaction(function () use ($db, $zip, $manifest, $order, $schema, $scope, $isStore, $dryRun, $dryRunDone, $accountId, $stores, $users, $actorUserId, $actor, &$restoredIds, &$counts, &$dropped): void {
            foreach ($order as $name => $table) {
                if (! isset($manifest['tables'][$name])) {
                    continue;
                }
                $rows = $this->rows($zip, $name);
                $columns = [];
                foreach ($schema->getColumns($name) as $column) {
                    $columns[$column['name']] = strtolower((string) $column['type_name']);
                }
                $selfRefs = array_values(array_filter(array_map(
                    fn (array $fk) => $fk['foreign_table'] === $name && count($fk['columns']) === 1 ? $fk['columns'][0] : null,
                    $schema->getForeignKeys($name),
                )));
                if (isset($columns['id'])) {
                    $ids = array_values(array_filter(array_map(fn (array $r) => $r['id'] ?? null, $rows), fn ($id) => $id !== null));
                    foreach (array_chunk($ids, 500) as $chunk) {
                        if ($db->table($name)->whereIn('id', $chunk)->exists()) {
                            throw new RuntimeException("{$name}: rows with the bundle's ids already exist — restore needs the ".($isStore ? 'store' : 'account').' absent');
                        }
                    }
                }

                $later = [];
                $batch = [];
                foreach ($rows as $row) {
                    $this->assertBelongs($name, $table, $row, $accountId, $stores, $users, $restoredIds);
                    if (isset($row['id'])) {
                        $restoredIds[$name][(string) $row['id']] = true;
                    }
                    foreach (array_diff(array_keys($row), array_keys($columns)) as $gone) {
                        $dropped[$name][$gone] = true;
                        unset($row[$gone]);
                    }
                    foreach ($table->secret ?? [] as $column => $make) {
                        if (isset($columns[$column]) && ! array_key_exists($column, $row)) {
                            $row[$column] = $make !== null ? $make() : null;
                        }
                    }
                    foreach ($row as $column => $value) {
                        if (in_array($columns[$column] ?? '', ['bool', 'boolean'], true) && $value !== null) {
                            $row[$column] = (bool) $value;
                        } elseif (is_array($value)) {
                            $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE);
                        }
                    }
                    foreach ($selfRefs as $column) {
                        if (($row[$column] ?? null) !== null && isset($row['id'])) {
                            $later[] = [$row['id'], $column, $row[$column]];
                            $row[$column] = null;
                        }
                    }
                    $batch[] = $row;
                    if (count($batch) >= 200) {
                        $db->table($name)->insert($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $db->table($name)->insert($batch);
                }
                foreach ($later as [$id, $column, $value]) {
                    $db->table($name)->where('id', $id)->update([$column => $value]);
                }
                $counts[$name] = count($rows);
                if (! $dryRun && isset($columns['id']) && $db->getDriverName() === 'pgsql' && $rows !== []) {
                    $this->moveSequence($db, $name);
                }
            }

            $db->table('store_data_operations')->insert([
                'operation' => 'restore',
                'scope_type' => $scope->type,
                'target_account_id' => $accountId,
                'target_store_id' => $isStore ? $scope->id : null,
                'actor_user_id' => $actorUserId,
                'actor' => $actor,
                'report' => json_encode(['tables' => $counts]),
                'created_at' => now(),
            ]);
            if ($dryRun) {
                throw $dryRunDone; // every check and insert ran; roll all of it back
            }
        });
        } catch (RuntimeException $e) {
            if ($e !== $dryRunDone) {
                throw $e;
            }
        }
        if ($dryRun) {
            ksort($counts);

            return ['scope' => $scope->label(), 'tables' => $counts, 'dropped_columns' => array_map(fn (array $c) => array_keys($c), $dropped), 'files' => 0, 'dry_run' => true];
        }

        $written = 0;
        foreach ($manifest['files'] ?? [] as $entry => $file) {
            $disk = Storage::disk((string) $file['disk']);
            $content = $zip->getFromName((string) $entry);
            if (str_contains((string) $file['path'], '..')) {
                continue;
            }
            if ($content !== false && ! $disk->exists((string) $file['path'])) {
                $disk->put((string) $file['path'], $content);
                $written++;
            }
        }
        foreach (array_keys($manifest['media'] ?? []) as $entry) {
            $target = $this->mediaTarget((string) $entry);
            $content = $zip->getFromName((string) $entry);
            if ($target !== null && $content !== false && ! is_file($target)) {
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                file_put_contents($target, $content);
                $written++;
            }
        }
        // BL-003: sounds, font and PDFs the export carries — only into the folders of the stores restored here.
        // Ids of the stores whose rows were just inserted here (not the manifest's own list).
        $uploadStores = array_map('intval', array_keys($restoredIds['stores'] ?? []));
        foreach (array_keys($manifest['uploads'] ?? []) as $entry) {
            $target = StoreUploadFiles::restoreTarget((string) $entry, $uploadStores);
            $content = $target !== null ? $zip->getFromName((string) $entry) : false;
            if ($target !== null && $content !== false && StoreUploadFiles::restoreFile($target, $content)) {
                $written++;
            }
        }
        TenantFill::flushStores();
        if ($isStore) {
            foreach (StoreDataRegistry::modules() as $data) {
                $data->forgetStoreCaches($scope->id);
            }
        }
        ksort($counts);

        return [
            'scope' => $isStore ? $scope->label().' into account:'.$accountId : 'account:'.$accountId,
            'tables' => $counts,
            'dropped_columns' => array_map(fn (array $c) => array_keys($c), $dropped),
            'files' => $written,
            'dry_run' => false,
        ];
    }

    /**
     * P6-92: a store bundle goes back only into the account it was exported from, while that account has the same
     * owner, and only when the store is gone (ids are kept — see the class doc).
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertStoreFitsAccount(Connection $db, ZipArchive $zip, array $manifest, int $storeId, int $accountId): void
    {
        $account = $db->table('accounts')->where('id', $accountId)->first();
        if ($account === null) {
            throw new RuntimeException("account {$accountId} does not exist — restore the whole account bundle instead");
        }
        $storeRows = isset($manifest['tables']['stores']) ? $this->rows($zip, 'stores') : [];
        if (count($storeRows) !== 1 || (int) ($storeRows[0]['id'] ?? 0) !== $storeId || array_map('intval', (array) ($manifest['stores'] ?? [])) !== [$storeId]) {
            throw new RuntimeException('store bundle must hold exactly its own store row — refused');
        }
        $store = $storeRows[0];
        if ((int) ($store['account_id'] ?? 0) !== $accountId) {
            throw new RuntimeException("store {$storeId} belongs to another account — a store restores only into its own account");
        }
        $owner = $store['user_id'] ?? null;
        $ownerId = $account->owner_user_id ?? null;
        if ($owner !== null && $ownerId !== null && (int) $owner !== (int) $ownerId) {
            throw new RuntimeException("store {$storeId} was owned by a user who is not the owner of account {$accountId} — refused");
        }
        if ($db->table('stores')->where('id', $storeId)->exists()) {
            throw new RuntimeException("store {$storeId} already exists — delete it first (ids are kept, never remapped)");
        }
    }

    /**
     * Tenancy guard: a bundle row may only belong to the bundle's account — never write a row of another account or
     * store (a tampered bundle is refused as a whole; the transaction rolls back).
     *
     * @param  array<string, mixed>  $row
     * @param  list<int>  $stores
     * @param  list<int>  $users
     * @param  array<string, array<string, true>>  $restored
     */
    private function assertBelongs(string $name, ?StoreTable $table, array $row, int $accountId, array $stores, array $users, array $restored): void
    {
        $ok = match (true) {
            $name === 'accounts' => (int) ($row['id'] ?? 0) === $accountId,
            $name === 'stores' => (int) ($row['account_id'] ?? 0) === $accountId && in_array((int) ($row['id'] ?? 0), $stores, true),
            $name === 'users' => (int) ($row['account_id'] ?? 0) === $accountId,
            $table?->kind === 'owned' => in_array((int) ($row[(string) $table->scopeColumn] ?? 0), $users, true),
            $table?->kind === 'child' => true, // its parent column is checked against the restored parent rows below
            // A tenant row: no key may name another account/store, and at least one must name this one (an export
            // only selects rows by account_id or store_id — a row with neither, e.g. a NULL-account coupon of
            // another user, is not the account's: bug fix D-P1-21-15).
            default => (! array_key_exists('account_id', $row) || $row['account_id'] === null || (int) $row['account_id'] === $accountId)
                && (! array_key_exists('store_id', $row) || $row['store_id'] === null || in_array((int) $row['store_id'], $stores, true))
                && ((isset($row['account_id']) && (int) $row['account_id'] === $accountId)
                    || (isset($row['store_id']) && in_array((int) $row['store_id'], $stores, true))),
        };
        if ($ok && $table?->kind === 'child') {
            $parentId = (string) ($row[(string) $table->scopeColumn] ?? '');
            $ok = isset($restored[(string) $table->parentTable][$parentId]);
        }
        if (! $ok) {
            throw new RuntimeException("bundle row of {$name} does not belong to account {$accountId} — refused");
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(ZipArchive $zip, string $table): array
    {
        $raw = $zip->getFromName('data/'.$table.'.ndjson');
        if ($raw === false) {
            throw new RuntimeException("bundle lists {$table} but has no data/{$table}.ndjson");
        }
        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) !== '') {
                $rows[] = (array) json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $rows;
    }

    /** A media entry (`media/<public|disk>/<dir>/<name>`) back to its absolute path; null for an unsafe entry. */
    private function mediaTarget(string $entry): ?string
    {
        if (str_contains($entry, '..') || ! str_starts_with($entry, 'media/')) {
            return null;
        }
        [, $where, $rest] = explode('/', $entry, 3) + [null, null, null];
        if ($where === null || $rest === null || $rest === '') {
            return null;
        }

        return $where === 'public' ? public_path($rest) : Storage::disk($where)->path($rest);
    }

    private function moveSequence(Connection $db, string $table): void
    {
        $sequence = $db->selectOne('select pg_get_serial_sequence(?, ?) as s', [$db->getTablePrefix().$table, 'id'])?->s;
        if ($sequence) {
            $db->statement('select setval(?, (select max(id) from '.$db->getQueryGrammar()->wrapTable($table).'))', [$sequence]);
        }
    }
}
