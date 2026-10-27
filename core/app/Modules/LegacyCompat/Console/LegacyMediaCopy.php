<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaIndex;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaPathMap;
use RuntimeException;
use Throwable;

/**
 * M6-B: copy the media files of the source install into this install's disks, guided by a JSON assignments
 * file, and record every decision in the resumable media manifest ({@see LegacyMediaManifest}).
 *
 * The command never overwrites or deletes a pre-existing target: a different target file is a conflict
 * and is left untouched. Only a file this run itself just wrote (and re-hashed badly) is removed.
 * Use --dry-run to list what would happen without writing anything.
 *
 * @phpstan-import-type MediaEntry from LegacyMediaManifest
 */
class LegacyMediaCopy extends Command
{
    protected $signature = 'legacy:media-copy
        {--from-disk= : Source disk name (falls back to legacy_compat.media.from_disk)}
        {--to-disk= : Target disk name (falls back to legacy_compat.media.to_disk)}
        {--assignments= : Local JSON file with the copy assignments}
        {--manifest= : Local manifest JSON path (falls back to legacy_compat.media.manifest)}
        {--csv= : Optional local CSV path written alongside the manifest}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Copy media from the source install layout to this install and record a resumable manifest.';

    /** Counted run statuses, in the order the summary line prints them. */
    private const STATUSES = ['copied', 'already_present', 'planned', 'conflict', 'missing', 'unmapped', 'invalid'];

    public function handle(): int
    {
        if (! LegacyCompatRoutes::enabled()) {
            $this->error('The legacy-compat module is off.');

            return self::FAILURE;
        }

        $gate = ImportGuard::gateReasons();
        if ($gate !== []) {
            $this->error('Refused:');
            foreach ($gate as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $fromDisk = $this->diskOption('from-disk', 'legacy_compat.media.from_disk');
        $toDisk = $this->diskOption('to-disk', 'legacy_compat.media.to_disk');
        $manifestPath = $this->option('manifest') ?: config('legacy_compat.media.manifest');
        $assignmentsPath = $this->option('assignments');
        $csvPath = $this->option('csv') ?: null;
        $dryRun = (bool) $this->option('dry-run');

        if ($fromDisk === null || $toDisk === null || $manifestPath === null || ! is_string($assignmentsPath) || $assignmentsPath === '') {
            $this->error('Missing option: --from-disk, --to-disk, --assignments and --manifest are all required.');

            return self::FAILURE;
        }

        if ($fromDisk === $toDisk) {
            $this->error('The source and target disks must differ.');

            return self::FAILURE;
        }

        if (config('filesystems.disks.'.$fromDisk) === null || config('filesystems.disks.'.$toDisk) === null) {
            $this->error('Unknown disk name in the filesystems config.');

            return self::FAILURE;
        }

        $assignments = $this->readAssignments($assignmentsPath);
        if ($assignments === null) {
            return self::FAILURE;
        }

        try {
            $manifest = LegacyMediaManifest::read($manifestPath);
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            throw $e;
        }

        $from = Storage::disk($fromDisk);
        $to = Storage::disk($toDisk);
        $counts = array_fill_keys(self::STATUSES, 0);
        $runEntries = [];

        try {
            foreach ($assignments as $assignment) {
                $entry = $this->copyOne($from, $to, $assignment, $dryRun);
                $counts[$entry['status']]++;
                $runEntries[] = $entry;

                if (! $dryRun) {
                    $manifest->put($entry);
                }
            }

            if (! $dryRun) {
                $manifest->write($manifestPath);
                if ($csvPath !== null) {
                    $manifest->toCsv($csvPath);
                }
            } else {
                $this->printEntries($runEntries);
            }
        } finally {
            if (! $dryRun) {
                try {
                    $manifest->write($manifestPath);
                    if ($csvPath !== null) {
                        $manifest->toCsv($csvPath);
                    }
                } catch (Throwable) {
                    // The in-try write already surfaced any write failure.
                }
            }
        }

        if (! $dryRun) {
            LegacyMediaIndex::build($manifest, $manifestPath);
            $this->line('index rebuilt');
        }

        $this->summaryLine($counts);

        $failed = $counts['conflict'] + $counts['missing'] + $counts['invalid'];

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy (or classify) one assignment and return its manifest entry.
     *
     * @param  array<string, mixed>  $assignment
     * @return MediaEntry
     */
    private function copyOne(FilesystemAdapter $from, FilesystemAdapter $to, array $assignment, bool $dryRun): array
    {
        $source = (string) $assignment['source'];
        $mapped = LegacyMediaPathMap::map($source, $assignment['account_id'], $assignment['store_id'], $assignment['user_id']);

        $entry = [
            'source' => $source,
            'target' => $mapped['target'],
            'size' => 0,
            'sha256' => null,
            'account_id' => $assignment['account_id'],
            'store_id' => $assignment['store_id'],
            'user_id' => $assignment['user_id'],
            'status' => '',
            'note' => null,
        ];

        if ($mapped['target'] === null) {
            $entry['status'] = $mapped['reason'] === 'invalid_path' ? 'invalid' : 'unmapped';
            $entry['note'] = $mapped['reason'];

            return $entry;
        }

        // The manifest records the normalized source; the file is read from the source disk under the path as given
        // (it may carry the public-disk prefix). A mapped target implies normalize() accepted the path, so no traversal.
        $normalized = LegacyMediaPathMap::normalize($source) ?? $source;
        $readPath = ltrim($source, '/');

        if (! $from->exists($readPath)) {
            $entry['source'] = $normalized;
            $entry['status'] = 'missing';

            return $entry;
        }

        [$size, $sha256] = $this->hashOnDisk($from, $readPath);
        $entry['source'] = $normalized;
        $entry['size'] = $size;
        $entry['sha256'] = $sha256;

        $target = (string) $mapped['target'];

        if ($to->exists($target)) {
            [$targetSize, $targetSha256] = $this->hashOnDisk($to, $target);
            $entry['status'] = $targetSha256 === $sha256 && $targetSize === $size ? 'already_present' : 'conflict';

            return $entry;
        }

        if ($dryRun) {
            $entry['status'] = 'planned';

            return $entry;
        }

        $to->writeStream($target, $from->readStream($readPath));

        [$targetSize, $targetSha256] = $this->hashOnDisk($to, $target);
        $entry['size'] = $targetSize;
        $entry['sha256'] = $targetSha256;

        if ($targetSha256 !== $sha256 || $targetSize !== $size) {
            $to->delete($target);
            $entry['status'] = 'conflict';
            $entry['note'] = 'copy_mismatch';

            return $entry;
        }

        $entry['status'] = 'copied';

        return $entry;
    }

    /**
     * Size and sha256 of one disk file, read streaming so large files are never loaded whole.
     *
     * @return array{int, string}
     */
    private function hashOnDisk(FilesystemAdapter $disk, string $path): array
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot open stream for '.$path);
        }

        $context = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return [$size, hash_final($context)];
    }

    /**
     * Validate and decode the assignments file: a JSON list of objects, each with a string source and
     * int-or-null ids. Returns null (after reporting) when the file is unusable.
     *
     * @return list<array<string, mixed>>|null
     */
    private function readAssignments(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->error('Cannot read assignments file: '.$path);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $this->error('Assignments file must be a JSON list of objects: '.$path);

            return null;
        }

        foreach ($decoded as $item) {
            if (! is_array($item) || ! isset($item['source']) || ! is_string($item['source']) || $item['source'] === '') {
                $this->error('Assignments file has an entry without a string source: '.$path);

                return null;
            }

            foreach (['account_id', 'store_id', 'user_id'] as $key) {
                if (array_key_exists($key, $item) && $item[$key] !== null && ! is_int($item[$key])) {
                    $this->error('Assignments entry has a non-integer '.$key.' : '.$path);

                    return null;
                }
            }
        }

        return $decoded;
    }

    /** An option value, falling back to the matching media config key; null when neither is set. */
    private function diskOption(string $option, string $configKey): ?string
    {
        $value = $this->option($option) ?: config($configKey);
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<MediaEntry>  $entries
     */
    private function printEntries(array $entries): void
    {
        $rows = array_map(static fn (array $entry): array => [
            (string) $entry['source'],
            $entry['target'] ?? '',
            (string) $entry['size'],
            $entry['sha256'] ?? '',
            $entry['account_id'] ?? '',
            $entry['store_id'] ?? '',
            $entry['user_id'] ?? '',
            (string) $entry['status'],
            $entry['note'] ?? '',
        ], $entries);

        $this->table(['source', 'target', 'size', 'sha256', 'account_id', 'store_id', 'user_id', 'status', 'note'], $rows);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summaryLine(array $counts): void
    {
        $this->line(implode(' ', array_map(static fn (string $status): string => $status.'='.$counts[$status], self::STATUSES)));
    }
}
