<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * P6-202: an in-command run lock held for exactly as long as the process runs.
 *
 * The scheduler's withoutOverlapping(N) mutex expires after N minutes, and it does not cover a manual or a queued
 * (admin button) run at all. This lock is an flock() on storage/framework/locks/<name>.lock:
 *  - held until release() or process exit, however long the dump takes (no expiry to outlive);
 *  - a crashed / killed / OOM'd run frees it automatically (the kernel drops flock on process exit), so a stale
 *    lock FILE never blocks the next run - the owner line inside it is diagnostics only;
 *  - needs no cache store, so it works on a shared host with CACHE_DRIVER=file or array.
 * Scope is one host (the backups live on that host's disk); flock on NFS is not relied on.
 */
final class RunLock
{
    /** @param resource $handle */
    private function __construct(private $handle, public readonly string $path) {}

    public static function path(string $name): string
    {
        return storage_path('framework/locks/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $name).'.lock');
    }

    /**
     * Non-blocking: the lock, or null when another live process holds it.
     *
     * @phpstan-impure
     */
    public static function acquire(string $name): ?self
    {
        $path = self::path($name);
        @mkdir(dirname($path), 0775, true);
        $h = @fopen($path, 'c+');
        if ($h === false) {
            throw new \RuntimeException('cannot open run lock '.$path);
        }
        if (! flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return null;
        }
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode(['pid' => getmypid(), 'host' => gethostname(), 'started_at' => date('c')]) ?: '');
        fflush($h);

        return new self($h, $path);
    }

    /** Who holds it (best effort, for the "skipped" message). */
    public static function owner(string $name): string
    {
        $raw = @file_get_contents(self::path($name));

        return is_string($raw) ? trim($raw) : '';
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            ftruncate($this->handle, 0);
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
