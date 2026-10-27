<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Console\Commands;

use Juwri\Modules\Media\Services\MediaReferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Report rows that name a storage/menu file which is no longer on disk.
 *
 * Those rows are why a menu shows photos for most items and the default
 * placeholder for a scattered few: the file was unlinked while another row
 * still pointed at it (duplicated items and cloned stores share filenames).
 * The deletion paths are guarded now; this finds the damage already done.
 *
 * Read-only. It never writes — repair is a deliberate, per-store decision.
 */
class AuditMedia extends Command
{
    protected $signature = 'storage:audit-media
                            {--store= : Limit the report to one restaurant id}
                            {--csv= : Also write the full row list to this path}';

    protected $description = 'List menu rows whose storage/menu image file is missing from disk';

    /** @var array<int, array{table: string, column: string, owner: string}> */
    private const SOURCES = [
        ['table' => 'items',            'column' => 'image',    'owner' => 'store_id'],
        ['table' => 'image_menus',     'column' => 'image',    'owner' => 'store_id'],
        ['table' => 'media_assets',    'column' => 'filename', 'owner' => 'store_id'],
        ['table' => 'categories', 'column' => 'image',    'owner' => 'store_id'],
        ['table' => 'modifiers',       'column' => 'image',    'owner' => null], // P1-06: add-ons + option images
        ['table' => 'menu_banners',    'column' => 'image',    'owner' => 'store_id'],
        ['table' => 'stories',         'column' => 'media_url', 'owner' => 'store_id'],
        // PARITY-RUN T10: QR logo overlays live in the same shared library.
        ['table' => 'qr_designs',      'column' => 'logo_file', 'owner' => 'store_id'],
    ];

    public function handle(): int
    {
        $storeFilter = $this->option('store') ? (int) $this->option('store') : null;
        $rows = [];
        $checked = 0;
        $existsCache = [];

        foreach (self::SOURCES as $source) {
            if (! Schema::hasTable($source['table'])
                || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }

            $owner = $source['owner'] && Schema::hasColumn($source['table'], $source['owner'])
                ? $source['owner']
                : null;

            $query = DB::table($source['table'])
                ->whereNotNull($source['column'])
                ->where($source['column'], '!=', '')
                ->where($source['column'], '!=', 'default.png');

            if ($storeFilter && $owner) {
                $query->where($owner, $storeFilter);
            }

            $select = ['id', $source['column'].' as filename'];
            if ($owner) {
                $select[] = $owner.' as owner_id';
            }

            $query->orderBy('id')->select($select)->chunk(1000, function ($chunk) use (&$rows, &$checked, &$existsCache, $source, $owner) {
                foreach ($chunk as $row) {
                    $checked++;
                    $file = (string) $row->filename;

                    if (! array_key_exists($file, $existsCache)) {
                        $existsCache[$file] = file_exists(public_path('storage/menu/'.$file));
                    }

                    if ($existsCache[$file]) {
                        continue;
                    }

                    $rows[] = [
                        'table' => $source['table'],
                        'id'    => $row->id,
                        'store' => $owner ? ($row->owner_id ?? '') : '',
                        'file'  => $file,
                    ];
                }
            });
        }

        if (! $rows) {
            $this->info("Checked {$checked} references — every file is on disk.");
            return self::SUCCESS;
        }

        $byStore = [];
        foreach ($rows as $row) {
            $key = $row['store'] === '' ? '(no store)' : $row['store'];
            $byStore[$key] = ($byStore[$key] ?? 0) + 1;
        }
        arsort($byStore);

        $this->warn(count($rows).' of '.$checked.' references point at a file that is gone.');
        $this->newLine();

        $titles = Schema::hasTable('stores')
            ? DB::table('stores')->whereIn('id', array_keys($byStore))->pluck('title', 'id')
            : collect();

        $this->table(
            ['Store', 'Title', 'Missing files'],
            collect($byStore)->take(40)->map(fn ($count, $store) => [
                $store,
                $titles[$store] ?? '',
                $count,
            ])->values()->all()
        );

        if (count($byStore) > 40) {
            $this->line('… and '.(count($byStore) - 40).' more stores. Use --csv to get the full list.');
        }

        if ($path = $this->option('csv')) {
            // Counted in bulk here rather than per row: on a big estate this is
            // tens of thousands of files and one query each would crawl.
            $shared = MediaReferences::countMany(array_column($rows, 'file'));

            $handle = fopen($path, 'w');
            fputcsv($handle, ['table', 'row_id', 'store_id', 'filename', 'rows_sharing_this_file'], escape: '\\');
            foreach ($rows as $row) {
                fputcsv($handle, [$row['table'], $row['id'], $row['store'], $row['file'], $shared[$row['file']] ?? 1], escape: '\\');
            }
            fclose($handle);
            $this->info('Wrote '.count($rows).' rows to '.$path);
        }

        return self::SUCCESS;
    }
}
