<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Console\Commands;

use Juwri\Modules\Hr\Models\StaffDocument;
use Illuminate\Console\Command;

/**
 * Move legacy staff HR documents (IDs, contracts, health certs) out of the
 * public disk and into private storage.
 *
 * Uploads made before the private-disk fix in StaffHrmController were stored
 * under storage/app/public — which is symlinked into the webroot and served
 * with no auth. New uploads already go to the private 'local' disk; this
 * command relocates the legacy files so no employee document remains
 * publicly fetchable. The stored `path` value is unchanged (it is relative
 * to the disk root either way), so the download route keeps working.
 *
 * Dry-run by default; pass --move to actually relocate files.
 */
class MigrateStaffDocsPrivate extends Command
{
    protected $signature = 'storage:migrate-staff-docs
                            {--move : Actually move files (default is a dry-run report)}';

    protected $description = 'Move legacy staff documents from public storage to the private disk';

    public function handle(): int
    {
        $move = (bool) $this->option('move');
        $moved = 0;
        $alreadyPrivate = 0;
        $missing = 0;
        $failed = 0;

        foreach (StaffDocument::query()->whereNotNull('path')->cursor() as $doc) {
            $private = storage_path('app/' . $doc->path);
            $public = storage_path('app/public/' . $doc->path);

            if (is_file($private)) {
                $alreadyPrivate++;
                continue;
            }
            if (!is_file($public)) {
                $missing++;
                $this->warn("Missing on both disks: #{$doc->id} {$doc->path}");
                continue;
            }

            if (!$move) {
                $moved++; // would move
                $this->line("Would move: #{$doc->id} {$doc->path}");
                continue;
            }

            $dir = dirname($private);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $failed++;
                $this->error("Cannot create dir for #{$doc->id}: {$dir}");
                continue;
            }
            if (@rename($public, $private)) {
                $moved++;
            } else {
                $failed++;
                $this->error("Rename failed for #{$doc->id}: {$doc->path}");
            }
        }

        $verb = $move ? 'Moved' : 'Would move';
        $this->info("{$verb}: {$moved} | already private: {$alreadyPrivate} | missing: {$missing} | failed: {$failed}");
        if (!$move && $moved > 0) {
            $this->comment('Dry-run only. Re-run with --move to relocate the files.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
