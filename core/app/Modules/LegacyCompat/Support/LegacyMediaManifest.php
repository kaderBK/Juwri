<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use App\Services\Backup\DatasetStreamWriter;
use RuntimeException;

/**
 * M6: the media migration manifest. One entry per source/target pair, keyed by both so a re-run replaces the row for
 * the same copy instead of duplicating it.
 *
 * @phpstan-type MediaEntry array{
 *     source: string,
 *     target: ?string,
 *     size: int,
 *     sha256: ?string,
 *     account_id: ?int,
 *     store_id: ?int,
 *     user_id: ?int,
 *     status: string,
 *     note: ?string
 * }
 */
final class LegacyMediaManifest
{
    /** Redirectable statuses: the file exists at the target under the source name. */
    public const REDIRECT_STATUSES = ['copied', 'already_present'];

    private const VERSION = 1;

    private const CSV_HEADER = ['source', 'target', 'size', 'sha256', 'account_id', 'store_id', 'user_id', 'status', 'note'];

    /** @var array<string, MediaEntry> */
    private array $entries = [];

    /**
     * Read a manifest. A missing file is an empty manifest; an unreadable file, invalid JSON or a different version
     * is an error, never silently treated as empty.
     */
    public static function read(string $path): self
    {
        $manifest = new self;

        if (! is_file($path)) {
            return $manifest;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read media manifest: '.$path);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! array_key_exists('version', $decoded) || $decoded['version'] !== self::VERSION) {
            throw new RuntimeException('Invalid media manifest (version): '.$path);
        }

        $entries = $decoded['entries'] ?? [];
        if (! is_array($entries)) {
            throw new RuntimeException('Invalid media manifest (entries): '.$path);
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['source']) || ! is_string($entry['source'])) {
                throw new RuntimeException('Invalid media manifest entry: '.$path);
            }
            $manifest->put(self::completeEntry($entry));
        }

        return $manifest;
    }

    /**
     * Add or replace one entry; the key is source + NUL + target.
     *
     * @param  MediaEntry  $entry
     */
    public function put(array $entry): void
    {
        $this->entries[$entry['source']."\0".($entry['target'] ?? '')] = $entry;
    }

    /**
     * All entries, sorted by source then target (null target last).
     *
     * @return list<MediaEntry>
     */
    public function entries(): array
    {
        $entries = array_values($this->entries);
        usort($entries, self::compareEntries(...));

        return $entries;
    }

    /** @return MediaEntry|null */
    public function get(string $source, ?string $target): ?array
    {
        return $this->entries[$source."\0".($target ?? '')] ?? null;
    }

    /**
     * Write the JSON manifest atomically (temp file in the target directory, then rename).
     */
    public function write(string $path): void
    {
        $json = json_encode(
            ['version' => self::VERSION, 'entries' => $this->entries()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('Cannot encode media manifest.');
        }

        $this->atomically($path, static function (string $tmp) use ($json): void {
            if (@file_put_contents($tmp, $json."\n") === false) {
                throw new RuntimeException('Cannot write media manifest temp file: '.$tmp);
            }
        });
    }

    /**
     * Write the manifest as CSV atomically, with the header row this class documents.
     */
    public function toCsv(string $path): void
    {
        $entries = $this->entries();

        $this->atomically($path, static function (string $tmp) use ($entries): void {
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Cannot open media manifest temp file: '.$tmp);
            }

            try {
                fputcsv($handle, self::CSV_HEADER, escape: '\\');
                foreach ($entries as $entry) {
                    // Source paths are old customer upload names: neutralize formula triggers (P6-70); the CSV is
                    // an ops copy only, the manifest is read back from JSON.
                    fputcsv($handle, DatasetStreamWriter::csvRow([
                        $entry['source'],
                        $entry['target'],
                        $entry['size'],
                        $entry['sha256'],
                        $entry['account_id'],
                        $entry['store_id'],
                        $entry['user_id'],
                        $entry['status'],
                        $entry['note'],
                    ]), escape: '\\');
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * The entry to redirect an old public URL to: a copied/already_present entry for the normalized source. Several
     * stores may share a source file name, so the lowest store_id wins (nulls last), then the lowest target.
     *
     * @return MediaEntry|null
     */
    public function findRedirect(string $normalizedSource): ?array
    {
        $best = null;

        foreach ($this->entries as $entry) {
            if ($entry['source'] !== $normalizedSource || ! self::isRedirectable($entry)) {
                continue;
            }
            $best = self::better($best, $entry);
        }

        return $best;
    }

    /**
     * One target per normalized source, picked with the same precedence as {@see findRedirect()}: a compiled view
     * the media index can shard without the redirect route reading the manifest itself.
     *
     * @return array<string, string>
     */
    public function redirectMap(): array
    {
        $best = [];

        foreach ($this->entries as $entry) {
            if (! self::isRedirectable($entry)) {
                continue;
            }
            $source = $entry['source'];
            $best[$source] = self::better($best[$source] ?? null, $entry);
        }

        $map = [];
        foreach ($best as $source => $entry) {
            $target = $entry['target'];
            if ($target !== null && $target !== '') {
                $map[$source] = $target;
            }
        }

        return $map;
    }

    /**
     * Write through a sibling temp file so readers never see a half-written manifest.
     *
     * @param  callable(string): void  $writer
     */
    private function atomically(string $path, callable $writer): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create media manifest directory: '.$directory);
        }

        // random temp name in the target directory (same filesystem, so rename() is atomic); no tempnam (arch preset)
        $tmp = $directory.'/.legacy-media-'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $writer($tmp);

            if (! @rename($tmp, $path)) {
                throw new RuntimeException('Cannot replace media manifest: '.$path);
            }
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return MediaEntry
     */
    private static function completeEntry(array $entry): array
    {
        return [
            'source' => (string) $entry['source'],
            'target' => isset($entry['target']) && $entry['target'] !== '' ? (string) $entry['target'] : null,
            'size' => (int) ($entry['size'] ?? 0),
            'sha256' => isset($entry['sha256']) ? (string) $entry['sha256'] : null,
            'account_id' => isset($entry['account_id']) ? (int) $entry['account_id'] : null,
            'store_id' => isset($entry['store_id']) ? (int) $entry['store_id'] : null,
            'user_id' => isset($entry['user_id']) ? (int) $entry['user_id'] : null,
            'status' => (string) ($entry['status'] ?? ''),
            'note' => isset($entry['note']) ? (string) $entry['note'] : null,
        ];
    }

    /**
     * @param  MediaEntry  $a
     * @param  MediaEntry  $b
     */
    private static function compareEntries(array $a, array $b): int
    {
        $bySource = strcmp($a['source'], $b['source']);
        if ($bySource !== 0) {
            return $bySource;
        }

        if ($a['target'] === $b['target']) {
            return 0;
        }
        if ($a['target'] === null) {
            return 1;
        }
        if ($b['target'] === null) {
            return -1;
        }

        return strcmp($a['target'], $b['target']);
    }

    /** @param  MediaEntry  $entry */
    private static function isRedirectable(array $entry): bool
    {
        return in_array($entry['status'], self::REDIRECT_STATUSES, true);
    }

    /**
     * @param  MediaEntry|null  $best
     * @param  MediaEntry  $entry
     * @return MediaEntry
     */
    private static function better(?array $best, array $entry): array
    {
        if ($best === null || self::precedes($entry, $best)) {
            return $entry;
        }

        return $best;
    }

    /**
     * @param  MediaEntry  $a
     * @param  MediaEntry  $b
     */
    private static function precedes(array $a, array $b): bool
    {
        if ($a['store_id'] !== $b['store_id']) {
            if ($a['store_id'] === null) {
                return false;
            }
            if ($b['store_id'] === null) {
                return true;
            }

            return $a['store_id'] < $b['store_id'];
        }

        return strcmp($a['target'] ?? '', $b['target'] ?? '') < 0;
    }
}
