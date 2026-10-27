<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Support\Facades\File;

/**
 * P6-320: files a store uploads OUTSIDE the media library and outside any table column — notification sounds, the
 * custom menu font, folio PDFs (and their pre-rastered pages) and the pageview PDF. Their names live in
 * store_options / are derived from the store id, so the table-based registry (StoreTable::files / media) cannot see
 * them. Export carries them (`uploads/<relative path>`), erasure and account purge delete them.
 *
 * Every path is built from an integer store id only; nothing here reads a user-supplied name.
 */
final class StoreUploadFiles
{
    /**
     * @return array<string, string> zip entry => absolute path (only files that exist)
     */
    public static function files(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $out = [];
        $add = function (string $relative) use (&$out): void {
            $absolute = public_path('storage/'.$relative);
            if (is_file($absolute)) {
                $out['uploads/'.$relative] = $absolute;
            }
        };

        $globs = [
            'notif-sounds/'.$storeId.'/*',
            'fonts/font_'.$storeId.'_*',
            'folio-pdf/'.$storeId.'-*.pdf',
            'folio-pdf-pages/'.$storeId.'/*',
            'pageview/'.$storeId.'-menu.pdf',
        ];
        foreach ($globs as $pattern) {
            foreach (glob(public_path('storage/'.$pattern)) ?: [] as $absolute) {
                $add(ltrim(substr($absolute, strlen(public_path('storage'))), '/'));
            }
        }

        return $out;
    }

    /**
     * BL-003: the absolute path an exported `uploads/<relative>` entry restores to, or null when the entry is not one
     * of the shapes {@see files()} produces for one of `$storeIds` (the stores this import is restoring). The store
     * id is read back out of the entry name and must be in that list, so a bundle can never write into another
     * store's folder, and nothing but a plain file name is accepted after the fixed folder (no `..`, no slash, no
     * leading dot, no other separator). The caller must never use a manifest value in place of this check.
     *
     * @param  list<int>  $storeIds
     */
    public static function restoreTarget(string $entry, array $storeIds): ?string
    {
        $name = '[A-Za-z0-9][A-Za-z0-9._-]{0,150}';
        $patterns = [
            '#^notif-sounds/(\d+)/'.$name.'$#',
            '#^fonts/font_(\d+)_'.$name.'$#',
            '#^folio-pdf/(\d+)-'.$name.'\.pdf$#',
            '#^folio-pdf-pages/(\d+)/'.$name.'$#',
            '#^pageview/(\d+)-menu\.pdf$#',
        ];
        if (! str_starts_with($entry, 'uploads/') || str_contains($entry, '..')) {
            return null;
        }
        $relative = substr($entry, strlen('uploads/'));
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $relative, $m) === 1) {
                if (! in_array((int) $m[1], $storeIds, true) || (int) $m[1] <= 0) {
                    return null;
                }

                return public_path('storage/'.$relative);
            }
        }

        return null;
    }

    /**
     * BL-003: write one upload from a bundle to `$target` (from {@see restoreTarget()}). Never overwrites, never
     * follows a symlink, and refuses when the resolved folder is not inside public/storage. Returns whether a file was written.
     */
    public static function restoreFile(string $target, string $content): bool
    {
        $root = realpath(public_path('storage'));
        if ($root === false || is_link($target) || file_exists($target)) {
            return false;
        }
        $dir = dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $resolved = realpath($dir);
        if ($resolved === false || ($resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
            return false;
        }

        return file_put_contents($resolved.DIRECTORY_SEPARATOR.basename($target), $content) !== false;
    }

    /** Delete them all (and the now-empty per-store folders). Returns the number of files removed. */
    public static function delete(int $storeId): int
    {
        $removed = 0;
        foreach (self::files($storeId) as $absolute) {
            if (@unlink($absolute)) {
                $removed++;
            }
        }
        if ($storeId > 0) {
            foreach (['notif-sounds/'.$storeId, 'folio-pdf-pages/'.$storeId] as $dir) {
                $path = public_path('storage/'.$dir);
                if (is_dir($path) && File::isEmptyDirectory($path)) {
                    @rmdir($path);
                }
            }
        }

        return $removed;
    }
}
