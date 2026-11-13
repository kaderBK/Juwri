<?php

use App\Support\HostingProfile;

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Backup binaries
    |--------------------------------------------------------------------------
    | C2: `config('backup.mysqldump_path')` was already read by
    | PlatformDbBackup but this file never existed, so the key always resolved
    | to null and the command relied on `mysqldump` being on PATH. The restore
    | drill needs a `mysql` client path on the same footing. Empty string keeps
    | the historical behaviour (bare binary name, resolved through PATH).
    */
    'mysqldump_path' => env('MYSQLDUMP_PATH', ''),
    'mysql_path' => env('MYSQL_PATH', ''),
    // Bug 5: PostgreSQL tools for the same commands (driver chosen from the connection).
    'pg_dump_path' => env('PG_DUMP_PATH', ''),
    'psql_path' => env('PSQL_PATH', ''),
    // P6-119a: `auto` uses the PHP-only dumper (PhpMysqlDump) when proc_open is disabled or mysqldump is missing
    // (shared hosting); `php` forces it; `native` always uses mysqldump/mysql. MySQL/MariaDB only.
    'dump_engine' => env('BACKUP_DUMP_ENGINE', HostingProfile::default('dump_engine', env('HOSTING_PROFILE'))), // P6-123: shared → php

    /*
    | Suffix appended to the live database name to build the throwaway schema
    | that `platform:db-restore-check` restores into. MUST be non-empty — the
    | command refuses to run when the scratch name would equal the live one.
    */
    'restore_check_suffix' => env('BACKUP_RESTORE_CHECK_SUFFIX', '_restore_check'),

    /*
    |--------------------------------------------------------------------------
    | Retention + encryption of the platform DB dumps (P1-23)
    |--------------------------------------------------------------------------
    | The security page and the SLA promise daily backups kept for 30 days, and
    | the Terms promise that deleted data leaves the backups within 90 days.
    | `keep_days` applies to the local dumps AND to their off-box copies (before
    | this, only the local dumps were pruned — at 14 days — and the S3 copies
    | were kept forever). `offbox_encryption` is the S3 server-side encryption
    | asked for on every off-box object ('' = do not send the header, for an
    | S3-compatible store that rejects it).
    */
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
    'offbox_encryption' => env('BACKUP_S3_SSE', 'AES256'),

    /*
    | P6-96: client-side encryption of every DB dump (local AND off-box) with
    | libsodium secretstream (XChaCha20-Poly1305). base64 of 32 random bytes
    | (`php artisan backup:generate-key`). Empty = plain gzip (self-host
    | default; `ops:preflight --cloud` fails without it). Keep rotated keys in
    | `encryption_previous_keys` (comma-separated) until their dumps age out:
    | an old dump restores only with the key it was made with.
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', ''),
    'encryption_previous_keys' => env('BACKUP_ENCRYPTION_PREVIOUS_KEYS', ''),

    /*
    |--------------------------------------------------------------------------
    | Media mirror (M7)
    |--------------------------------------------------------------------------
    | The repo-root storage/ tree (public_path('storage'), NOT core/storage) is
    | where every uploaded image lives — ~798 MB / ~26k files on the reference
    | box. It is in no platform artifact. `platform:media-backup` mirrors it to
    | the platform S3 disk incrementally.
    |
    | An EXCLUDE list, never an include list: MigrateMediaToS3::MEDIA_DIRS is an
    | allowlist that already rotted (it misses marketplace/, public-media/,
    | partner/, payment_gateways/, payments/, qr-templates/, fonts/). A new
    | upload path must be covered by default.
    */
    'media_root' => env('PLATFORM_MEDIA_ROOT', ''),   // '' = public_path('storage')
    'media_prefix' => env('PLATFORM_MEDIA_PREFIX', 'backups/platform/media'),
    'media_exclude' => ['log.html', 'cache', 'tmp', '.DS_Store'],
    'media_max_files_per_run' => (int) env('PLATFORM_MEDIA_MAX_FILES', 20000),
];
