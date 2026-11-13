<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Console\Commands;

use Juwri\Modules\Media\Services\StorageQuota;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abuse Guard P5 (5.4): backfill the per-tenant storage byte counters from the
 * media_assets already on disk, so StorageQuota can enforce against current usage
 * instead of only counting uploads made after the guard shipped. Idempotent —
 * writes an ABSOLUTE total per owner into account_usage (metric storage_bytes).
 */
class StorageBackfill extends Command
{
    protected $signature = 'abuse:storage-backfill';
    protected $description = 'Recompute per-tenant storage byte counters from existing media assets';

    public function handle(): int
    {
        if (! Schema::hasTable('media_assets')) {
            $this->warn('media_assets table not found — nothing to backfill.');

            return self::SUCCESS;
        }

        $bytesByOwner = [];

        // 1) media_assets carry exact size_bytes.
        $p = DB::getTablePrefix(); // raw SUM() isn't prefixed by the query builder
        $rows = DB::table('media_assets')
            ->join('stores', 'stores.id', '=', 'media_assets.store_id')
            ->select('stores.user_id', DB::raw("SUM({$p}media_assets.size_bytes) as bytes"))
            ->groupBy('stores.user_id')
            ->pluck('bytes', 'stores.user_id');
        foreach ($rows as $ownerId => $bytes) {
            $bytesByOwner[(int) $ownerId] = ($bytesByOwner[(int) $ownerId] ?? 0) + (int) $bytes;
        }

        // 2) Other image tables store only a filename — stat it on local disk (S3
        //    files aren't local, so they're skipped; the counter stays a safe floor).
        $this->addDiskBytes($bytesByOwner, 'image_menus', 'store_id', 'image', 'storage/menu/');
        $this->addDiskBytes($bytesByOwner, 'items', 'store_id', 'image', 'storage/menu/');

        $n = 0;
        foreach ($bytesByOwner as $ownerId => $bytes) {
            if ($ownerId <= 0) {
                continue;
            }
            StorageQuota::put((int) $ownerId, (int) $bytes);
            $n++;
        }

        $this->info("Backfilled storage counters for {$n} tenants.");

        return self::SUCCESS;
    }

    /**
     * Add on-disk byte sizes for an image column of $table (joined to posts by
     * $restaurantCol) into the running per-owner map. Missing files/tables skip.
     */
    protected function addDiskBytes(array &$bytesByOwner, string $table, string $restaurantCol, string $imageCol, string $dir): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->join('stores', 'stores.id', '=', $table . '.' . $restaurantCol)
            ->whereNotNull($table . '.' . $imageCol)
            ->where($table . '.' . $imageCol, '!=', '')
            ->where($table . '.' . $imageCol, '!=', 'default.png')
            ->select('stores.user_id', $table . '.' . $imageCol . ' as img')
            ->orderBy($table . '.id')
            ->chunk(1000, function ($rows) use (&$bytesByOwner, $dir) {
                foreach ($rows as $r) {
                    $path = public_path($dir . $r->img);
                    if (is_file($path)) {
                        $bytesByOwner[(int) $r->user_id] = ($bytesByOwner[(int) $r->user_id] ?? 0) + (int) @filesize($path);
                    }
                }
            });
    }
}
