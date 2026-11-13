<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use RuntimeException;
use ZipArchive;

/**
 * WI-R1: bound the SUPERADMIN backup import archive before it is extracted.
 *
 * Context — read this before changing anything here. An audit claimed a zip-slip
 * RCE at Admin\UserController::fullImport. It was REFUTED: ZipArchive::extractTo()
 * sanitises entry names through php_zip_make_relative_path(), stripping "../" and
 * absolute prefixes and materialising symlink entries as regular files, so no
 * write escapes the destination directory. The traversal/symlink checks below are
 * belt-and-braces that cost one statIndex() loop we are already making — they are
 * NOT the reason this class exists.
 *
 * The reason this class exists is that neither admin import path applied any
 * entry-count, uncompressed-size or compression-ratio limit. `max:102400` on the
 * upload caps only COMPRESSED bytes, so a 100 MB zip of zeros could inflate to
 * ~100 GB into storage/app/user-backups/ — the same disk that holds the platform
 * DB dumps, sessions and logs.
 *
 * Siblings, deliberately NOT merged into one class (different caps, different
 * layout rules, separate test suites, no defect behind a merge):
 *   - Platform's PluginZipValidator                               (plugins:  2000 / 50 MB / 10 MB)
 *   - Juwri\Modules\Storefront\Storefront\ThemePackageValidator    (themes:   1000 / 10 MB / 2 MB)
 *   - App\Services\Backup\StoreImportService::validate() (owner store backups)
 *
 * Caps are operator-tunable via two Options so a genuinely large tenant can be
 * imported without a deploy; the RATIO cap is a security parameter and is a
 * constant.
 */
class BackupZipValidator
{
    /** Defaults; overridden by config('settings.admin_import_max_entries') / _uncompressed_mb. */
    public const DEFAULT_MAX_ENTRIES = 20000;

    public const DEFAULT_MAX_UNCOMPRESSED_MB = 1024;   // 1 GiB, as StoreImportService

    /** uncompressed:compressed. Not operator-tunable — a ratio is a bomb signature, not capacity. */
    public const MAX_RATIO = 200;

    public static function maxEntries(): int
    {
        $v = (int) config('settings.admin_import_max_entries', self::DEFAULT_MAX_ENTRIES);

        return $v > 0 ? $v : self::DEFAULT_MAX_ENTRIES;
    }

    public static function maxUncompressedBytes(): int
    {
        $mb = (int) config('settings.admin_import_max_uncompressed_mb', self::DEFAULT_MAX_UNCOMPRESSED_MB);
        $mb = $mb > 0 ? $mb : self::DEFAULT_MAX_UNCOMPRESSED_MB;

        return $mb * 1024 * 1024;
    }

    /**
     * Inspect an OPEN ZipArchive without extracting it.
     *
     * @throws RuntimeException with an already-translated, operator-readable message.
     */
    public static function assertSafe(ZipArchive $zip): void
    {
        $maxEntries = self::maxEntries();
        if ($zip->numFiles > $maxEntries) {
            throw new RuntimeException(___('Backup archive has too many files').' ('.$zip->numFiles.' > '.$maxEntries.').');
        }

        $maxBytes = self::maxUncompressedBytes();
        $uncompressed = 0;
        $compressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];
            if ($name === '' || str_contains($name, "\0")) {
                throw new RuntimeException(___('Backup archive contains an invalid entry name.'));
            }

            // Belt-and-braces only — extractTo() already strips these. See the class docblock.
            $normalized = str_replace('\\', '/', $name);
            if (
                str_starts_with($normalized, '/')
                || preg_match('#^[a-zA-Z]:#', $normalized)
                || preg_match('#(^|/)\.\.(/|$)#', $normalized)
            ) {
                throw new RuntimeException(___('Backup archive contains an illegal path').': '.$name);
            }

            $uncompressed += (int) $stat['size'];
            $compressed += (int) $stat['comp_size'];

            if ($uncompressed > $maxBytes) {
                throw new RuntimeException(
                    ___('Backup archive is too large when uncompressed').
                    ' (> '.(int) round($maxBytes / 1048576).' MB).'
                );
            }
        }

        if ($compressed > 0 && $uncompressed / max(1, $compressed) > self::MAX_RATIO) {
            throw new RuntimeException(___('Backup archive compression ratio looks like a zip bomb.'));
        }
    }
}
