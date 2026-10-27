<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\StoreDataExporter;

/** P1-21 part 2: export one account or one store to a store-data bundle (zip: JSON per table + files; no secrets). */
class StoreDataExportCommand extends Command
{
    protected $signature = 'store-data:export {--account= : Account id} {--store= : Store id} {--path= : Zip to write (default storage/app/store-data/<scope>-<time>.zip)}';

    protected $description = 'Export one account (or one store) to a JSON + files bundle, secrets excluded';

    public function handle(StoreDataExporter $exporter): int
    {
        $scope = StoreDataCommandScope::from($this);
        if ($scope === null) {
            return self::INVALID;
        }
        $path = (string) ($this->option('path') ?: storage_path('app/store-data/'.$scope->type.'-'.$scope->id.'-'.now()->format('Ymd-His').'.zip'));
        $manifest = $exporter->export(DB::connection(), $scope, $path);
        DB::table('store_data_operations')->insert([
            'operation' => 'export', 'scope_type' => $scope->type,
            'target_account_id' => $scope->type === 'account' ? $scope->id : null,
            'target_store_id' => $scope->type === 'store' ? $scope->id : null,
            'actor' => 'console',
            'report' => json_encode(['tables' => array_map(fn (array $t) => $t['rows'], $manifest['tables']), 'excluded' => array_keys($manifest['excluded']['tables'])]),
            'created_at' => now(),
        ]);

        $this->info("Exported {$scope->label()}: ".count($manifest['tables']).' tables, '.array_sum(array_column($manifest['tables'], 'rows')).' rows, '.count($manifest['files']).' files -> '.$path);
        $this->line('Excluded (secrets): '.implode(', ', array_keys($manifest['excluded']['tables'])));

        return self::SUCCESS;
    }
}
