<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\StoreDataImporter;
use RuntimeException;

/**
 * P6-92: restore ONE store from a store bundle (`store-data:export --store=`) into its existing, live account. Same
 * account and owner only, signed by this installation, store absent (ids kept) — see {@see StoreDataImporter}.
 */
class StoreRestoreBundleCommand extends Command
{
    protected $signature = 'stores:restore-bundle {path : Store bundle zip} {--account= : The live account to restore into}
        {--dry-run : Run every check and insert, then roll back} {--confirm= : Type the scope back, e.g. store:12}';

    protected $description = 'Restore one store from a signed store bundle into its existing account (ids kept; secrets reset)';

    public function handle(StoreDataImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $account = (string) $this->option('account');
        if (! ctype_digit($account)) {
            $this->error('Pass --account=<id> (the live account the store belongs to).');

            return self::INVALID;
        }
        $zip = new \ZipArchive;
        $manifest = null;
        if (is_file($path) && $zip->open($path) === true) {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $zip->close();
        }
        if (! is_array($manifest) || ($manifest['scope']['type'] ?? null) !== 'store') {
            $this->error("Not a store bundle: {$path}");

            return self::FAILURE;
        }
        $label = 'store:'.(int) ($manifest['scope']['id'] ?? 0);
        $dry = (bool) $this->option('dry-run');
        if (! $dry) {
            $typed = $this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask("Type {$label} to restore it into account {$account}") : null);
            if ($typed !== $label) {
                $this->error('Not confirmed — nothing restored.');

                return self::FAILURE;
            }
        }
        try {
            $report = $importer->import(DB::connection(), $path, null, 'console', (int) $account, $dry);
        } catch (RuntimeException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }
        $what = array_sum($report['tables']).' rows in '.count($report['tables']).' tables';
        $this->info($dry ? "Dry run OK for {$report['scope']}: {$what} would be restored (rolled back)." : "Restored {$report['scope']}: {$what}, {$report['files']} files.");
        foreach ($report['dropped_columns'] as $table => $columns) {
            $this->warn("{$table}: columns not in this schema, dropped: ".implode(', ', $columns));
        }

        return self::SUCCESS;
    }
}
