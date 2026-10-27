<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P1-04 slice 6: inspect / refresh the PostgreSQL RLS policies (e.g. after TenantTables was regenerated for a new
 * module table). `status` exits 1 when a tenant table lacks RLS or its policy, or is FORCEd.
 */
final class TenancyRlsCommand extends Command
{
    protected $signature = 'tenancy:rls {action=status : status|sync|drop} {--database= : connection name}';

    protected $description = 'Show, sync or drop the tenant row-level-security policies (PostgreSQL only)';

    public function handle(): int
    {
        $db = DB::connection($this->option('database') ?: null);
        if (! RowLevelSecurity::supported($db)) {
            $this->info('Not PostgreSQL: row-level security is not used on '.$db->getDriverName().' (app scoping only).');

            return self::SUCCESS;
        }

        return match ($this->argument('action')) {
            'sync' => $this->done('synced', RowLevelSecurity::sync($db)),
            'drop' => $this->done('dropped', RowLevelSecurity::drop($db)),
            default => $this->status($db),
        };
    }

    private function done(string $what, int $n): int
    {
        $this->info("RLS {$what} on {$n} tenant tables.");

        return self::SUCCESS;
    }

    private function status(ConnectionInterface $db): int
    {
        $status = RowLevelSecurity::status($db);
        $bad = array_filter($status, fn ($s) => ! $s['rls'] || ! $s['policy'] || $s['force']);
        $this->info(count($status).' tenant tables, '.(count($status) - count($bad)).' protected.');
        foreach ($bad as $table => $s) {
            $this->warn(sprintf('%s: rls=%s policy=%s force=%s', $table, $s['rls'] ? 'on' : 'off', $s['policy'] ? 'yes' : 'no', $s['force'] ? 'yes' : 'no'));
        }

        return $bad === [] ? self::SUCCESS : self::FAILURE;
    }
}
