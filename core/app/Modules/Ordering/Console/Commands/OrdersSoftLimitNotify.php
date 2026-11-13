<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Contracts\Billing\PlanQuota;

/**
 * HPMS E3: soft monthly-order quota notifier. For every store owner whose plan
 * sets `orders_monthly_soft`, fire ONE notification per calendar month once this
 * month's orders reach the allowance. Idempotent (per-owner-per-month marker);
 * NEVER blocks an order — the banner half lives in DashboardAttentionService.
 */
class OrdersSoftLimitNotify extends Command
{
    protected $signature = 'quota:order-soft-notify';

    protected $description = 'Notify owners once/month when they reach their plan order allowance (E3)';

    public function handle(): int
    {
        $sent = 0;
        // Distinct store owners — one pass per owner (bounded; a daily job).
        $ownerIds = Store::query()->whereNotNull('user_id')->distinct()->pluck('user_id');
        foreach ($ownerIds as $ownerId) {
            try {
                $owner = User::find($ownerId);
                if ($owner && app(PlanQuota::class)->notifyOrderSoftOnce($owner)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Order soft-limit notifications sent: {$sent}");

        return self::SUCCESS;
    }
}
