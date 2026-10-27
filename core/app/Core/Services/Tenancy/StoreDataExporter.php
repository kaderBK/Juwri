<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Settings\StoreSettings;
use RuntimeException;
use ZipArchive;

/**
 * P1-21 part 2 (J29-5): one account (or one store) as a portable bundle, driven by the store-data registry.
 *
 * A zip with `manifest.json`, `data/<table>.ndjson` (one JSON row per line, ids as stored) for every registered table
 * that has rows in scope, the files the rows own (`files/<disk>/<path>`, {@see StoreTable::files()}) and their media
 * (`media/<location>/<name>`, {@see StoreTable::media()}). Secrets never leave: tables declared notExported (vaulted
 * credentials, certificates), secret columns (passwords, 2FA, tokens, gateway credentials) and the owner's connection
 * settings in `stores.settings` are left out — and listed under `excluded` in the manifest.
 */
final class StoreDataExporter
{
    public const FORMAT = 'juwri.store-data';

    public const VERSION = 1;

    public const SIGNATURE_ENTRY = 'manifest.sig';

    /** @return array<string, mixed> the manifest */
    public function export(Connection $db, StoreDataScope $scope, string $zipPath): array
    {
        return TenantContext::bypass(fn (): array => $this->build($db, $scope, $zipPath));
    }

    /** @return array<string, mixed> */
    private function build(Connection $db, StoreDataScope $scope, string $zipPath): array
    {
        $work = storage_path('app/store-data/export-'.Str::lower(Str::random(12)));
        if (! is_dir($work.'/data') && ! mkdir($work.'/data', 0700, true) && ! is_dir($work.'/data')) {
            throw new RuntimeException("cannot create {$work}");
        }

        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'scope' => ['type' => $scope->type, 'id' => $scope->id],
            'exported_at' => now()->toIso8601String(),
            'stores' => $scope->storeIds($db),
            'users' => $scope->userIds($db),
            'tables' => [],
            'excluded' => ['tables' => [], 'columns' => [], 'settings' => []],
            'files' => [],
            'media' => [],
        ];
        $zipFiles = [];

        if ($scope->type === 'account') {
            $account = $db->table('accounts')->where('id', $scope->id)->first();
            if ($account === null) {
                throw new RuntimeException("account {$scope->id} does not exist");
            }
            file_put_contents($work.'/data/accounts.ndjson', json_encode((array) $account, JSON_UNESCAPED_UNICODE)."\n");
            $manifest['tables']['accounts'] = ['module' => 'Core', 'kind' => 'root', 'rows' => 1];
        } elseif (! $db->table('stores')->where('id', $scope->id)->exists()) {
            throw new RuntimeException("store {$scope->id} does not exist");
        }

        foreach ($scope->orderedTables($db) as $table) {
            $query = $scope->query($db, $table);
            if ($query === null) {
                continue;
            }
            if (! $table->exported) {
                $count = (clone $query)->count();
                $manifest['excluded']['tables'][$table->table] = ['rows' => $count, 'reason' => $table->note ?? 'secrets — the owner reconnects'];

                continue;
            }
            if ($table->secret !== []) {
                $manifest['excluded']['columns'][$table->table] = array_keys($table->secret);
            }

            $handle = fopen($work.'/data/'.$table->table.'.ndjson', 'wb');
            if ($handle === false) {
                throw new RuntimeException("cannot write {$table->table}");
            }
            $rows = 0;
            foreach ($this->rows($db, $query, $table->table) as $source) {
                $row = (array) $source;
                foreach (array_keys($table->secret) as $column) {
                    unset($row[$column]);
                }
                if ($table->table === 'stores') {
                    $row = $this->withoutOwnerSettings($row, $manifest['excluded']['settings']);
                }
                foreach ($table->files as $column => $disk) {
                    $path = $row[$column] ?? null;
                    if (is_string($path) && $path !== '' && Storage::disk($disk)->exists($path)) {
                        $entry = 'files/'.$disk.'/'.ltrim($path, '/');
                        $zipFiles[$entry] = Storage::disk($disk)->path($path);
                        $manifest['files'][$entry] = ['table' => $table->table, 'column' => $column, 'disk' => $disk, 'path' => $path];
                    }
                }
                foreach ($this->mediaOf($table, $row) as $entry => $absolute) {
                    $zipFiles[$entry] = $absolute;
                    $manifest['media'][$entry] = $table->table;
                }
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
                $rows++;
            }
            fclose($handle);
            if ($rows === 0) {
                unlink($work.'/data/'.$table->table.'.ndjson');

                continue;
            }
            $manifest['tables'][$table->table] = ['module' => $table->module, 'kind' => $table->kind, 'rows' => $rows];
        }
        foreach ($scope->storeIds($db) as $storeId) { // P6-320: uploads that live outside any table
            foreach (StoreUploadFiles::files((int) $storeId) as $entry => $absolute) {
                $zipFiles[$entry] = $absolute;
                $manifest['uploads'][$entry] = (int) $storeId; // BL-003: the importer restores these, but re-derives the store id from the name and trusts nothing here
            }
        }
        ksort($manifest['excluded']['tables']);
        ksort($manifest['excluded']['columns']);
        $manifest['excluded']['settings'] = array_values(array_unique($manifest['excluded']['settings']));
        sort($manifest['excluded']['settings']);

        $this->zip($work, $zipPath, $manifest, $zipFiles);
        $this->rmdir($work);

        return $manifest;
    }

    /** @return iterable<object> */
    private function rows(Connection $db, Builder $query, string $table): iterable
    {
        if ($db->getSchemaBuilder()->hasColumn($table, 'id')) {
            return $query->lazyById(500, $table.'.id', 'id');
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $excluded
     * @return array<string, mixed>
     */
    private function withoutOwnerSettings(array $row, array &$excluded): array
    {
        $settings = is_string($row['settings'] ?? null) ? json_decode($row['settings'], true) : null;
        if (! is_array($settings)) {
            return $row;
        }
        $names = array_values(array_filter(
            array_map('strval', array_keys(StoreSettings::legacyMap((int) $row['id']))),
            fn (string $n) => StoreDataRegistry::isOwnerSetting($n),
        ));
        if ($names !== []) {
            $row['settings'] = json_encode(StoreSettings::without($settings, $names), JSON_UNESCAPED_UNICODE);
            array_push($excluded, ...$names);
        }

        return $row;
    }

    /**
     * Media files a row names: zip entry => absolute path (only files that exist).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function mediaOf(StoreTable $table, array $row): array
    {
        $out = [];
        foreach ($table->media as $column => $locations) {
            foreach (self::mediaNames($column, $row) as $name) {
                foreach ($locations as $location) {
                    $absolute = self::mediaPath($location, $row, $name);
                    if (is_file($absolute)) {
                        $out[self::mediaEntry($location, $row, $name)] = $absolute;

                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public static function mediaNames(string $column, array $row): array
    {
        $list = str_ends_with($column, '[]');
        $value = $row[$list ? substr($column, 0, -2) : $column] ?? null;
        $names = $list ? (is_string($value) ? (array) json_decode($value, true) : (array) $value) : [$value];
        $out = [];
        foreach ($names as $name) {
            $name = is_array($name) ? ($name['image'] ?? null) : $name;
            if (is_string($name) && $name !== '' && $name !== 'default.png' && ! str_contains($name, '..') && ! str_contains($name, '/')) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row */
    public static function mediaDir(string $location, array $row): string
    {
        [, $dir] = explode(':', $location, 2);

        return str_replace('{store_id}', (string) ($row['store_id'] ?? ''), $dir);
    }

    /** @param  array<string, mixed>  $row */
    public static function mediaPath(string $location, array $row, string $name): string
    {
        [$where] = explode(':', $location, 2);
        $dir = self::mediaDir($location, $row);

        return $where === 'public' ? public_path($dir.$name) : Storage::disk($where)->path($dir.$name);
    }

    /** @param  array<string, mixed>  $row */
    public static function mediaEntry(string $location, array $row, string $name): string
    {
        [$where] = explode(':', $location, 2);

        return 'media/'.$where.'/'.self::mediaDir($location, $row).$name;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    private function zip(string $work, string $zipPath, array $manifest, array $files): void
    {
        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0700, true);
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("cannot create {$zipPath}");
        }
        $entries = [];
        foreach (glob($work.'/data/*.ndjson') ?: [] as $file) {
            $entries['data/'.basename($file)] = $file;
        }
        $entries += $files;
        // P6-92: every entry's checksum in the manifest, the manifest signed with this installation's key.
        $manifest['checksums'] = [];
        foreach ($entries as $entry => $absolute) {
            $manifest['checksums'][$entry] = (string) hash_file('sha256', $absolute);
        }
        ksort($manifest['checksums']);
        $json = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $zip->addFromString('manifest.json', $json);
        $signature = self::signature($json);
        if ($signature !== null) {
            $zip->addFromString(self::SIGNATURE_ENTRY, $signature);
        }
        foreach ($entries as $entry => $absolute) {
            $zip->addFile($absolute, $entry);
        }
        $zip->close();
    }

    /** HMAC of the manifest with a key derived from APP_KEY; null when the installation has no key. */
    public static function signature(string $manifestJson): ?string
    {
        $key = (string) config('app.key');

        return $key === '' ? null : hash_hmac('sha256', $manifestJson, hash_hmac('sha256', self::FORMAT.'.signature', $key));
    }

    /**
     * P6-92: refuse a bundle this installation did not produce or that was changed after export — the manifest's
     * signature must match, every entry must have its checksum, and no entry may be added or missing.
     */
    public static function verify(ZipArchive $zip): void
    {
        $json = $zip->getFromName('manifest.json');
        $given = $zip->getFromName(self::SIGNATURE_ENTRY);
        $expected = is_string($json) ? self::signature($json) : null;
        if ($expected === null || ! is_string($given) || ! hash_equals($expected, trim($given))) {
            throw new RuntimeException('bundle signature missing or invalid (not exported by this installation, or edited) — refused');
        }
        $checksums = (array) (json_decode($json, true)['checksums'] ?? []);
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === 'manifest.json' || $name === self::SIGNATURE_ENTRY) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (! isset($checksums[$name]) || ! is_string($content) || ! hash_equals((string) $checksums[$name], hash('sha256', $content))) {
                throw new RuntimeException("bundle entry {$name} was changed or added after export — refused");
            }
            $seen[$name] = true;
        }
        if (array_diff_key($checksums, $seen) !== []) {
            throw new RuntimeException('bundle entries are missing — refused');
        }
    }

    private function rmdir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        @rmdir($dir);
    }
}
