<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Services\Security\SecretsAtRest;
use Illuminate\Console\Command;

/**
 * Encrypt stored credentials at rest (store gateway secrets → vault, platform
 * secrets → encrypted in place). Safe to re-run; --dry-run only counts.
 *
 * Encrypted values depend on APP_KEY: never rotate it in production without
 * re-encrypting, or every stored credential becomes unreadable.
 */
class SecretsEncrypt extends Command
{
    protected $signature = 'secrets:encrypt {--dry-run : Count what would change without writing}';

    protected $description = 'Encrypt stored payment-gateway and platform credentials at rest (idempotent)';

    public function handle(SecretsAtRest $secrets): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = $secrets->run($dryRun);

        $this->info($dryRun ? 'Dry run — nothing was written.' : 'Done.');
        $this->table(['Item', $dryRun ? 'Would change' : 'Count'], [
            ['Store secrets moved into the encrypted vault', $stats['store_moved']],
            ['Empty store secret placeholders removed', $stats['store_empty_removed']],
            ['Store secret rows left untouched (store no longer exists)', $stats['store_orphans_left']],
            ['Platform secrets encrypted', $stats['platform_encrypted']],
            ['Platform secrets already encrypted', $stats['platform_already_encrypted']],
            ['All orphan store_options rows (report only, not changed)', $stats['orphan_store_options']],
        ]);

        return self::SUCCESS;
    }
}
