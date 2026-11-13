<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Juwri\Modules\Ordering\Models\CustomerNotification;
use Illuminate\Console\Command;

/**
 * Lane A / A6 — retention for the customer notification inbox.
 *
 * customer_notifications otherwise grows unbounded (every status flip of every
 * order for every logged-in diner) and keeps orphans when a customer or order
 * is deleted (no FK on the table by design). Three rules, all query-builder
 * (never raw SQL with table names — prod runs a table prefix):
 *
 *   1. read rows older than 90 days go away;
 *   2. each customer keeps at most their newest 200 rows;
 *   3. rows pointing at a deleted customer, or at a deleted order, go away.
 */
class PruneCustomerNotifications extends Command
{
    protected $signature = 'notifications:prune-customer';

    protected $description = 'Prune customer inbox notifications: read >90d, per-customer cap 200, orphans';

    private const READ_RETENTION_DAYS = 90;

    private const PER_CUSTOMER_CAP = 200;

    public function handle(): int
    {
        // 1. Old read rows.
        $oldRead = CustomerNotification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(self::READ_RETENTION_DAYS))
            ->delete();

        // 2. Per-customer cap: for each customer over the cap, delete everything
        //    beyond their newest N ids. Chunked per customer — no raw SQL.
        $capped = 0;
        CustomerNotification::query()
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > ?', [self::PER_CUSTOMER_CAP])
            ->pluck('customer_id')
            ->each(function ($customerId) use (&$capped) {
                $keep = CustomerNotification::where('customer_id', $customerId)
                    ->latest('id')
                    ->limit(self::PER_CUSTOMER_CAP)
                    ->pluck('id');
                $capped += CustomerNotification::where('customer_id', $customerId)
                    ->whereNotIn('id', $keep)
                    ->delete();
            });

        // 3a. Orphans: customer gone.
        $orphanCustomers = CustomerNotification::query()
            ->leftJoin('customers', 'customers.id', '=', 'customer_notifications.customer_id')
            ->whereNull('customers.id')
            ->pluck('customer_notifications.id');
        $orphans = $orphanCustomers->isEmpty() ? 0
            : CustomerNotification::whereIn('id', $orphanCustomers)->delete();

        // 3b. Orphans: order_id set but the order is gone.
        $orphanOrders = CustomerNotification::query()
            ->whereNotNull('customer_notifications.order_id')
            ->leftJoin('orders', 'orders.id', '=', 'customer_notifications.order_id')
            ->whereNull('orders.id')
            ->pluck('customer_notifications.id');
        $orphans += $orphanOrders->isEmpty() ? 0
            : CustomerNotification::whereIn('id', $orphanOrders)->delete();

        $this->info("Pruned: {$oldRead} old-read, {$capped} over-cap, {$orphans} orphaned.");

        return self::SUCCESS;
    }
}
