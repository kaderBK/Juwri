<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * Stops the `playwright run-server` that pest-plugin-browser leaves behind.
 *
 * The plugin starts the server as soon as a browser test FILE is loaded — so a `--filter` run or a full run starts it
 * even when no browser test runs — through `sh -c ./node_modules/.bin/playwright run-server …` (Symfony Process with a
 * shell command line). At the end it sends SIGTERM to that `sh`, which does not forward it: the `node` child is orphaned,
 * keeps the run's ssh session open and, on the CI runner, the slot lock. This reaper runs at PHP shutdown of the process
 * that loaded the suite and stops the `node` server of THIS checkout that is left over:
 *
 *  - a server whose `sh` parent is this PHP process's child (this run started it), or
 *  - an orphaned server of this checkout (its `sh` parent is gone) — a live run's server always has its `sh` parent.
 *
 * Linux only (/proc); elsewhere a no-op. Never touches another checkout's processes.
 */
final class PlaywrightReaper
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || PHP_OS_FAMILY !== 'Linux' || ! is_dir('/proc/self')) {
            return;
        }
        self::$registered = true;
        $root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $self = getmypid();
        register_shutdown_function(static fn () => self::reap($root, (int) $self));
    }

    /** @return list<int> the pids it stopped */
    public static function reap(string $root, int $self): array
    {
        $stopped = [];
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $pid = (int) basename($dir);
            $args = self::args($pid);
            if (! str_contains($args, 'playwright run-server') || str_starts_with($args, 'sh ')) {
                continue; // only the node server itself (its sh wrapper dies with the run)
            }
            if ((@readlink($dir.'/cwd') ?: '') !== $root) {
                continue; // another checkout
            }
            $parent = self::ppid($pid);
            $parentArgs = $parent > 1 ? self::args($parent) : '';
            $ours = $parent > 1 && str_contains($parentArgs, 'playwright run-server') && self::ppid($parent) === $self;
            $orphan = ! str_contains($parentArgs, 'playwright run-server');
            if (($ours || $orphan) && self::kill($pid)) {
                $stopped[] = $pid;
            }
        }

        return $stopped;
    }

    private static function kill(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 9);
        }
        @exec('kill -9 '.$pid, $out, $code);

        return $code === 0;
    }

    private static function args(int $pid): string
    {
        $raw = @file_get_contents('/proc/'.$pid.'/cmdline');

        return is_string($raw) ? trim(str_replace("\0", ' ', $raw)) : '';
    }

    private static function ppid(int $pid): int
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (! is_string($stat) || ($close = strrpos($stat, ')')) === false) {
            return 0;
        }
        $fields = explode(' ', trim(substr($stat, $close + 2)));

        return (int) ($fields[1] ?? 0);
    }
}
