<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use RuntimeException;
use ZipArchive;

/**
 * P0-5: validate an uploaded plugin zip BEFORE it is extracted or its manifest
 * is required. Blocks path traversal, symlinks, zip bombs, and oversized/over-
 * numerous archives — the difference between "install a plugin" and "write
 * arbitrary files anywhere / RCE on upload".
 *
 * Note: plugins are PHP by design, so requiring plugin.php still executes
 * trusted code. This class ensures the archive can't escape plugins/<id>/ and
 * that <id> is a safe slug. The plugin.json migration (parse, don't execute)
 * is the tracked follow-up.
 */
class PluginZipValidator
{
    public const MAX_ENTRIES = 2000;
    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;   // 50 MB uncompressed
    public const MAX_ENTRY_BYTES = 10 * 1024 * 1024;   // 10 MB per file

    /** @throws RuntimeException when the archive is unsafe or malformed. */
    public static function assertSafe(string $zipPath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not enabled.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the plugin zip file.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Plugin archive has too many files.');
            }

            $total = 0;
            $hasManifest = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';

                if ($name === '' || str_contains($name, "\0")) {
                    throw new RuntimeException('Plugin archive contains an invalid entry name.');
                }
                // Path traversal / absolute paths / windows drive / UNC.
                $normalized = str_replace('\\', '/', $name);
                if (
                    str_starts_with($normalized, '/')
                    || preg_match('#^[a-zA-Z]:#', $normalized)
                    || preg_match('#(^|/)\.\.(/|$)#', $normalized)
                ) {
                    throw new RuntimeException('Plugin archive contains an illegal path: '.$name);
                }
                // Symlink detection via unix mode in external attributes (high 16 bits).
                $externalAttr = $stat['external_attr'] ?? 0;
                $unixMode = ($externalAttr >> 16) & 0xFFFF;
                if (($unixMode & 0xF000) === 0xA000) { // S_IFLNK
                    throw new RuntimeException('Plugin archive contains a symlink: '.$name);
                }

                $size = (int) ($stat['size'] ?? 0);
                if ($size > self::MAX_ENTRY_BYTES) {
                    throw new RuntimeException('Plugin archive contains an oversized file: '.$name);
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('Plugin archive is too large (possible zip bomb).');
                }

                // B2: plugin.json (parsed) is the preferred manifest; plugin.php
                // (executed) remains accepted for legacy archives.
                if (preg_match('#(^|/)plugin\.(json|php)$#', $normalized)) {
                    // Manifest must be at the archive root, not nested arbitrarily deep.
                    if (substr_count(rtrim($normalized, '/'), '/') <= 1) {
                        $hasManifest = true;
                    }
                }
            }

            if (! $hasManifest) {
                throw new RuntimeException('Plugin archive is missing plugin.json (or legacy plugin.php) at its root.');
            }
        } finally {
            $zip->close();
        }
    }

    /** A plugin id must be a bare slug — it becomes a filesystem path segment. */
    public static function isSafePluginId(mixed $id): bool
    {
        return is_string($id) && $id !== '' && (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $id);
    }
}
