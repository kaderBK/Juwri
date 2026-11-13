<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Database\MigrationChecksums;

/**
 * P6-38: add new migration files to database/migration-checksums.json. Listed entries are never rewritten
 * (an edited released migration stays a failing MigrationChecksumsTest); --rebuild is for the one-off reset only.
 * P6-171: new files also go into migration-checksums.baseline.json (never rewritten); a --rebuild that moves a released
 * entry needs an ME entry in core/database/MIGRATION-EDITS.md (MigrationEditsGuardTest).
 */
class MigrationChecksumsCommand extends Command
{
    protected $signature = 'juwri:migrations:checksums {--rebuild : Recompute every entry (only with an owner decision)}';

    protected $description = 'Record fingerprints of new migration files (released migrations must never change).';

    public function handle(): int
    {
        $added = MigrationChecksums::write(base_path(), (bool) $this->option('rebuild'));
        $this->info(count($added).' migration(s) added to '.MigrationChecksums::MANIFEST);
        foreach ($added as $path) {
            $this->line('  '.$path);
        }

        return self::SUCCESS;
    }
}
