<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use App\Support\SearchTerm;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use RuntimeException;

/**
 * P1-21 part 2 (J29-5, GDPR / PDPL erasure): delete ONE account or ONE store through the store-data registry.
 *
 * Every registered table's rows in scope ({@see StoreDataScope}) are deleted children-first (foreign keys of the live
 * schema) inside one transaction; the `accounts` row last for an account. After commit the files the rows owned are
 * deleted, and media files no remaining row names. A platform row in `store_data_operations` records what was deleted
 * (ids and counts only — no personal data). Rows of other accounts are never selected: every query is built from the
 * one account / store id. Superadmin only (console command `store-data:delete`, typed confirmation).
 *
 * Not the owner's self-service deletion (AccountDeletionService: grace period, financial records anonymised and kept);
 * this is the hard erasure.
 */
final class StoreDataEraser
{
    /** @return array{scope: string, tables: array<string, int>, files: int, media: int} */
    public function delete(Connection $db, StoreDataScope $scope, ?int $actorUserId = null, string $actor = 'console'): array
    {
        return TenantContext::bypass(function () use ($db, $scope, $actorUserId, $actor): array {
            if ($scope->type === 'account' ? ! $db->table('accounts')->where('id', $scope->id)->exists() : ! $db->table('stores')->where('id', $scope->id)->exists()) {
                throw new RuntimeException("{$scope->label()} does not exist");
            }
            $storeIds = $scope->storeIds($db);
            $tables = array_reverse($scope->orderedTables($db));
            $files = [];
            $media = [];
            $counts = [];

            $db->transaction(function () use ($db, $scope, $tables, &$files, &$media, &$counts, $actorUserId, $actor, $storeIds): void {
                // Rows OUTSIDE the scope, per table, before anything is deleted.
                $outside = [];
                foreach ($tables as $table) {
                    $inScope = $scope->query($db, $table);
                    $outside[$table->table] = $db->table($table->table)->count() - ($inScope === null ? 0 : $inScope->count());
                }
                foreach ($tables as $table) {
                    $query = $scope->query($db, $table);
                    if ($query === null) {
                        continue;
                    }
                    if (self::ownsFiles($table)) {
                        foreach ((clone $query)->get() as $row) {
                            $this->collect($table, (array) $row, $files, $media);
                        }
                    }
                    $deleted = $query->delete();
                    if ($deleted > 0) {
                        $counts[$table->table] = $deleted;
                    }
                }
                if ($scope->type === 'account') {
                    $counts['accounts'] = $db->table('accounts')->where('id', $scope->id)->delete();
                }
                // Never another account's row: a database cascade from a row in scope (a cross-account reference)
                // would delete more than the scope selected — refuse and roll everything back.
                foreach ($outside as $name => $before) {
                    $after = $db->table($name)->count();
                    if ($after !== $before) {
                        throw new RuntimeException("{$name}: deleting {$scope->label()} would also delete ".($before - $after).' row(s) outside it (a cross-account reference) — nothing deleted');
                    }
                }

                $db->table('store_data_operations')->insert([
                    'operation' => 'delete',
                    'scope_type' => $scope->type,
                    'target_account_id' => $scope->type === 'account' ? $scope->id : null,
                    'target_store_id' => $scope->type === 'store' ? $scope->id : null,
                    'actor_user_id' => $actorUserId,
                    'actor' => $actor,
                    'report' => json_encode(['tables' => $counts, 'stores' => $storeIds, 'files' => count($files)]),
                    'created_at' => now(),
                ]);
            });

            foreach ($storeIds as $storeId) {
                TenantFill::forgetStore($storeId);
                foreach (StoreDataRegistry::modules() as $data) {
                    $data->forgetStoreCaches($storeId);
                }
            }
            $removed = $this->removeFiles($db, $files, $media);
            foreach ($storeIds as $storeId) {
                $removed += StoreUploadFiles::delete((int) $storeId); // P6-320: sounds, font, folio / pageview PDFs
            }
            ksort($counts);

            return ['scope' => $scope->label(), 'tables' => $counts, 'files' => count($files), 'media' => $removed];
        });
    }

    /**
     * Files a row owns that erasure deletes but an export does not carry (P4-07): the store's own backup archives and
     * uploaded import archives (a bundle holding them would nest whole copies of the data).
     *
     * @var array<string, array<string, string>>
     */
    public const ERASE_ONLY_FILES = [
        'store_backups' => ['path' => 'local'],
        'store_imports' => ['path' => 'local'],
    ];

    public static function ownsFiles(StoreTable $table): bool
    {
        return $table->files !== [] || $table->media !== [] || isset(self::ERASE_ONLY_FILES[$table->table]);
    }

    /**
     * After the rows are gone (committed): delete the files they owned, and their media files no remaining row names.
     * Also used by the owner's account purge (AccountDeletionService). Returns the media files removed.
     *
     * @param  list<array{0: string, 1: string}>  $files
     * @param  array<string, array{0: string, 1: string, 2: string}>  $media
     */
    public function removeFiles(Connection $db, array $files, array $media): int
    {
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
        $removed = 0;
        foreach ($media as [$location, $name, $absolute]) {
            if (! $this->stillReferenced($db, $location, $name) && is_file($absolute) && @unlink($absolute)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The files / media one row owns (into $files / $media).
     *
     * @param  array<string, mixed>  $row
     * @param  list<array{0: string, 1: string}>  $files
     * @param  array<string, array{0: string, 1: string, 2: string}>  $media
     */
    public function collect(StoreTable $table, array $row, array &$files, array &$media): void
    {
        foreach ($table->files + (self::ERASE_ONLY_FILES[$table->table] ?? []) as $column => $disk) {
            if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                $files[] = [$disk, $row[$column]];
            }
        }
        foreach ($table->media as $column => $locations) {
            foreach (StoreDataExporter::mediaNames($column, $row) as $name) {
                foreach ($locations as $location) {
                    $absolute = StoreDataExporter::mediaPath($location, $row, $name);
                    if (is_file($absolute)) {
                        $media[$absolute] = [$location, $name, $absolute];

                        break;
                    }
                }
            }
        }
    }

    /** Does any remaining row of any table with media at $location still name this file? (shared media library) */
    private function stillReferenced(Connection $db, string $location, string $name): bool
    {
        foreach (StoreDataRegistry::tables() as $table) {
            foreach ($table->media as $column => $locations) {
                if (! in_array($location, $locations, true)) {
                    continue;
                }
                $list = str_ends_with($column, '[]');
                $col = $list ? substr($column, 0, -2) : $column;
                $query = $db->table($table->table);
                $list ? $query->whereRaw($query->getGrammar()->wrap($col).(SearchTerm::isPgsql($query) ? '::text' : '')." like ? escape '!'", ['%'.SearchTerm::escape($name).'%']) : $query->where($col, $name);
                if ($query->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
