<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\StoreDataImporter;

/** P1-21 part 2: restore one account from a store-data bundle into a database that does not hold it. */
class StoreDataImportCommand extends Command
{
    protected $signature = 'store-data:import {path : Bundle zip} {--confirm= : Type the bundle scope back, e.g. account:12}';

    protected $description = 'Restore one account from a store-data bundle (ids kept; secrets reset)';

    public function handle(StoreDataImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $zip = new \ZipArchive;
        $manifest = null;
        if ($zip->open($path) === true) {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $zip->close();
        }
        $label = is_array($manifest) ? ($manifest['scope']['type'] ?? '?').':'.($manifest['scope']['id'] ?? '?') : null;
        if ($label === null) {
            $this->error("Not a store-data bundle: {$path}");

            return self::FAILURE;
        }
        $typed = $this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask("Type {$label} to restore it") : null);
        if ($typed !== $label) {
            $this->error('Not confirmed — nothing restored.');

            return self::FAILURE;
        }
        $report = $importer->import(DB::connection(), $path);
        $this->info("Restored {$report['scope']}: ".array_sum($report['tables']).' rows in '.count($report['tables']).' tables, '.$report['files'].' files.');
        foreach ($report['dropped_columns'] as $table => $columns) {
            $this->warn("{$table}: columns not in this schema, dropped: ".implode(', ', $columns));
        }

        return self::SUCCESS;
    }
}
