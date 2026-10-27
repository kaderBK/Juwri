<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use RuntimeException;
use Throwable;

/**
 * M6-B: re-verify the copied media against the manifest. Every entry with status copied or already_present
 * must exist on the target disk with the recorded size and sha256 (read streaming). Problems are reported
 * one per line and never repaired; an empty manifest verifies zero entries and succeeds.
 *
 * @phpstan-import-type MediaEntry from LegacyMediaManifest
 */
class LegacyMediaVerify extends Command
{
    protected $signature = 'legacy:media-verify
        {--to-disk= : Target disk name (falls back to legacy_compat.media.to_disk)}
        {--manifest= : Local manifest JSON path (falls back to legacy_compat.media.manifest)}';

    protected $description = 'Verify copied media files on the target disk against the manifest hashes and sizes.';

    public function handle(): int
    {
        if (! LegacyCompatRoutes::enabled()) {
            $this->error('The legacy-compat module is off.');

            return self::FAILURE;
        }

        $toDisk = $this->option('to-disk') ?: config('legacy_compat.media.to_disk');
        $manifestPath = $this->option('manifest') ?: config('legacy_compat.media.manifest');

        if (! is_string($toDisk) || $toDisk === '' || ! is_string($manifestPath) || $manifestPath === '') {
            $this->error('Missing option: --to-disk and --manifest are both required.');

            return self::FAILURE;
        }

        if (config('filesystems.disks.'.$toDisk) === null) {
            $this->error('Unknown disk name in the filesystems config.');

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

        $disk = Storage::disk($toDisk);
        $verified = 0;
        $mismatch = 0;
        $missing = 0;

        foreach ($manifest->entries() as $entry) {
            if (! in_array($entry['status'], LegacyMediaManifest::REDIRECT_STATUSES, true) || $entry['target'] === null) {
                continue;
            }

            $verified++;
            $target = $entry['target'];

            if (! $disk->exists($target)) {
                $missing++;
                $this->line('MISSING '.$target);

                continue;
            }

            [$size, $sha256] = $this->hashOnDisk($disk, $target);

            if ($size !== $entry['size'] || $sha256 !== $entry['sha256']) {
                $mismatch++;
                $this->line('MISMATCH '.$target);
            }
        }

        $this->line('verified='.$verified.' mismatch='.$mismatch.' missing='.$missing);

        return $mismatch + $missing > 0 ? self::FAILURE : self::SUCCESS;
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
}
