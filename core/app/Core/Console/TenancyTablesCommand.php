<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console;

use Illuminate\Console\Command;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Services\Tenancy\TenantTablesGenerator;

/**
 * Regenerates the tenant-table lists from the migrations (TenantTablesGenerator): Community's TenantTables.php and
 * each installed edition package's tenant_tables.php. `--check` writes nothing and fails when a list is stale.
 */
final class TenancyTablesCommand extends Command
{
    protected $signature = 'tenancy:tables {--check : report stale lists and exit 1 instead of writing}';

    protected $description = 'Generate the tenant-table lists (Community + edition packages) from the migrations';

    public function handle(): int
    {
        $generator = new TenantTablesGenerator(base_path(), EditionPackages::tenantTablePackages());

        if ($this->option('check')) {
            $stale = $generator->stale();
            foreach ($stale as $file) {
                $this->error('stale: '.$file);
            }
            if ($stale === []) {
                $this->info('Tenant-table lists are current.');
            }

            return $stale === [] ? self::SUCCESS : self::FAILURE;
        }

        $written = $generator->write();
        foreach ($written as $file) {
            $this->info('written: '.$file);
        }
        if ($written === []) {
            $this->info('Tenant-table lists are current.');
        }

        return self::SUCCESS;
    }
}
