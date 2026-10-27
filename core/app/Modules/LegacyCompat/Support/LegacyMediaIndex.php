<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use RuntimeException;
use Throwable;

/**
 * M6-D: a compiled, sharded index next to the media manifest. The redirect route reads one small shard instead of
 * parsing the whole manifest per request, including for misses. The index is tied to the manifest's mtime and size:
 * a changed manifest without a rebuild makes every lookup return null (fail closed) rather than serve a stale target.
 */
final class LegacyMediaIndex
{
    private const VERSION = 1;

    /** Where the shards and meta.json live: a sibling directory of the manifest file. */
    public static function directory(string $manifestPath): string
    {
        return $manifestPath.'.index';
    }

    /** The manifest's mtime-size stamp, or null when it is missing. */
    public static function fingerprint(string $manifestPath): ?string
    {
        clearstatcache(true, $manifestPath);

        if (! is_file($manifestPath)) {
            return null;
        }

        $mtime = @filemtime($manifestPath);
        $size = @filesize($manifestPath);
        if ($mtime === false || $size === false) {
            return null;
        }

        return $mtime.'-'.$size;
    }

    /**
     * Compile the manifest's redirect map into 256 sha256-prefixed shards and write meta.json last. Shard files from
     * an earlier build that are no longer used are removed. All writes go through a temp file and rename.
     */
    public static function build(LegacyMediaManifest $manifest, string $manifestPath): void
    {
        $fingerprint = self::fingerprint($manifestPath);
        if ($fingerprint === null) {
            throw new RuntimeException('Cannot index a missing media manifest: '.$manifestPath);
        }

        $directory = self::directory($manifestPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create media index directory: '.$directory);
        }

        $shards = [];
        foreach ($manifest->redirectMap() as $source => $target) {
            $shards[substr(hash('sha256', $source), 0, 2)][$source] = $target;
        }

        $written = [];
        foreach ($shards as $shard => $entries) {
            $name = $shard.'.json';
            self::writeJson($directory.'/'.$name, $entries);
            $written[$name] = true;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === 'meta.json' || isset($written[$name])) {
                continue;
            }
            if (preg_match('/^[0-9a-f]{2}\.json$/', $name) === 1) {
                @unlink($directory.'/'.$name);
            }
        }

        self::writeJson($directory.'/meta.json', ['version' => self::VERSION, 'fingerprint' => $fingerprint]);
    }

    /**
     * The target for one normalized source from the compiled index, or null. Never reads the manifest body; any
     * missing, stale or unreadable index file is a miss, never an exception.
     */
    public static function lookup(string $manifestPath, string $normalizedSource): ?string
    {
        try {
            $fingerprint = self::fingerprint($manifestPath);
            if ($fingerprint === null) {
                return null;
            }

            $directory = self::directory($manifestPath);
            $meta = self::readJson($directory.'/meta.json');
            if ($meta === null || ($meta['version'] ?? null) !== self::VERSION || ($meta['fingerprint'] ?? null) !== $fingerprint) {
                return null;
            }

            $shard = self::readJson($directory.'/'.substr(hash('sha256', $normalizedSource), 0, 2).'.json');
            if ($shard === null) {
                return null;
            }

            $target = $shard[$normalizedSource] ?? null;

            return is_string($target) && $target !== '' ? $target : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $entries */
    private static function writeJson(string $path, array $entries): void
    {
        $json = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Cannot encode media index file: '.$path);
        }

        $directory = dirname($path);
        // random temp name in the target directory (same filesystem, so rename() is atomic); no tempnam (arch preset)
        $tmp = $directory.'/.legacy-media-index-'.bin2hex(random_bytes(8)).'.tmp';

        try {
            if (@file_put_contents($tmp, $json."\n") === false) {
                throw new RuntimeException('Cannot write media index temp file: '.$tmp);
            }
            if (! @rename($tmp, $path)) {
                throw new RuntimeException('Cannot replace media index file: '.$path);
            }
        } catch (Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
