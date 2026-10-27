<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\StoreDataEraser;

/**
 * P1-21 part 2 (GDPR / PDPL erasure): permanently delete one account or one store — every registered table, its files.
 * Superadmin operation on the console; the scope must be typed back (`--confirm=account:<id>`) or confirmed
 * interactively. Recorded in store_data_operations (ids and counts only).
 */
class StoreDataDeleteCommand extends Command
{
    protected $signature = 'store-data:delete {--account= : Account id} {--store= : Store id} {--confirm= : Type the scope back, e.g. account:12}';

    protected $description = 'Permanently delete one account (or one store) and its files through the store-data registry';

    public function handle(StoreDataEraser $eraser): int
    {
        $scope = StoreDataCommandScope::from($this);
        if ($scope === null) {
            return self::INVALID;
        }
        $typed = $this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask("Type {$scope->label()} to delete it permanently") : null);
        if ($typed !== $scope->label()) {
            $this->error('Not confirmed — nothing deleted.');

            return self::FAILURE;
        }
        $report = $eraser->delete(DB::connection(), $scope);
        $this->info("Deleted {$report['scope']}: ".array_sum($report['tables']).' rows in '.count($report['tables']).' tables, '.$report['files'].' files, '.$report['media'].' media files.');

        return self::SUCCESS;
    }
}
