<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Services\Billing\PlanLimitSync;
use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Throwable;

/** P6-220: switch over-limit branches/staff off after a downgrade or expiry, and back on after an upgrade. */
class PlanLimitsSyncCommand extends Command
{
    protected $signature = 'plan-limits:sync';

    protected $description = 'Keep active branches and staff within each owner\'s plan limits (never deletes; restores on upgrade).';

    public function handle(PlanLimitSync $sync): int
    {
        $suspended = 0;
        $restored = 0;
        $failed = 0;
        $ownerIds = Store::withoutGlobalScopes()->whereNotNull('user_id')->distinct()->pluck('user_id');
        foreach ($ownerIds->chunk(200) as $chunk) {
            foreach (User::withoutGlobalScopes()->whereIn('id', $chunk)->get() as $owner) {
                try {
                    $r = $sync->sync($owner);
                    $suspended += $r['suspended'];
                    $restored += $r['restored'];
                } catch (Throwable $e) {
                    $failed++; // one owner never stops the rest
                    report($e);
                }
            }
        }
        $this->info("plan-limits:sync suspended={$suspended} restored={$restored} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
