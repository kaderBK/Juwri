<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\BranchHourException;

/** P6-291: delete holiday / special-hours rows older than 2 days (UTC), so no branch timezone can still need them. */
class PruneBranchHourExceptionsCommand extends Command
{
    protected $signature = 'branches:prune-hour-exceptions';

    protected $description = 'Delete past branch holiday and special-hours dates';

    public function handle(): int
    {
        $n = BranchHourException::withoutGlobalScopes()->where('date', '<', now('UTC')->subDays(2)->toDateString())->delete();
        $this->info("Pruned {$n} past date(s).");

        return self::SUCCESS;
    }
}
