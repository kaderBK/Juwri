<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

/**
 * P6-123 (J40): one `HOSTING_PROFILE=shared|cpanel-vps|docker` setting with per-profile defaults.
 * Config files call {@see self::default()} (with env('HOSTING_PROFILE')) as the fallback of their own env() lookup, so an explicit
 * env value (QUEUE_CONNECTION, CACHE_DRIVER, ...) always wins. Read straight from env (not config())
 * because config files load in any order. `docker` (also the default when unset or unknown) equals the
 * behaviour before profiles existed — existing installs see no change.
 */
final class HostingProfile
{
    public const PROFILES = ['shared', 'cpanel-vps', 'docker'];

    public const DEFAULT = 'docker';

    /**
     * @var array<string, array<string, string|int|bool|null>>
     */
    public const DEFAULTS = [
        // cPanel without root: no daemons, everything drains from the minute cron.
        'shared' => [
            'queue_connection' => 'database',
            'worker_mode' => 'cron',            // schedule:run drains with queue:work --stop-when-empty
            'heavy_max_time' => 600,            // shared hosts reap long cron children; retry_after stays 1860
            'default_max_time' => 45,           // P6-119c: the default drain stays under typical cron-child reaping
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'lock_store' => null,               // session block lock uses the cache store (file)
            'broadcast_connection' => 'null',   // no Reverb daemon: screens poll
            'dump_engine' => 'php',             // proc_open / mysqldump usually unavailable
            'scheduler_mode' => 'cron',
            'redis' => false,
        ],
        // cPanel/WHM with root: real cron + supervisor/systemd workers, Redis optional.
        'cpanel-vps' => [
            'queue_connection' => 'database',
            'worker_mode' => 'supervisor',
            'heavy_max_time' => 1800,
            'default_max_time' => 50,
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'lock_store' => null,
            'broadcast_connection' => 'null',
            'dump_engine' => 'auto',
            'scheduler_mode' => 'cron',
            'redis' => 'optional',
        ],
        // Today's behaviour, unchanged (the config fallbacks that existed before P6-123).
        'docker' => [
            'queue_connection' => 'sync',
            'worker_mode' => 'container',
            'heavy_max_time' => 1800,
            'default_max_time' => 50,
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'lock_store' => null,
            'broadcast_connection' => 'null',
            'dump_engine' => 'auto',
            'scheduler_mode' => 'container',
            'redis' => 'optional',
        ],
    ];

    /** Normalise a raw HOSTING_PROFILE value (config files pass env('HOSTING_PROFILE')); unknown → docker. */
    public static function normalize(mixed $raw): string
    {
        $value = strtolower(trim(is_string($raw) ? $raw : ''));

        return in_array($value, self::PROFILES, true) ? $value : self::DEFAULT;
    }

    public static function default(string $key, mixed $profile): string|int|bool|null
    {
        return self::DEFAULTS[self::normalize($profile)][$key] ?? null;
    }
}
