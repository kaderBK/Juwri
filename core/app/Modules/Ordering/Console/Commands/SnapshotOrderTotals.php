<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * R1c: backfill the subtotal / tax_total / grand_total snapshot columns onto
 * historical orders so Lane D · D3's SQL aggregates cover the whole table, not
 * just orders placed after this item shipped.
 *
 * Idempotent — {@see \App\Models\Order::snapshotTotals()} only writes when a
 * value actually changed, so re-running is a cheap no-op. Chunked to keep memory
 * flat across the ~millions of rows in a mature prod store. `--force` re-snapshots
 * every row; by default only rows still missing a grand_total, or with a line still missing its unit price, are touched.
 *
 * BL-049: a line placed before every line froze its unit price is frozen first at the price it derives today
 * ({@see OrderItem::freezeUnitPrice()}), so a later menu price edit can no longer re-total the order.
 */
class SnapshotOrderTotals extends Command
{
    protected $signature = 'orders:snapshot-totals {--force : Re-snapshot every order, not just those missing a grand_total} {--chunk=500 : Rows per chunk} {--dry-run : Report what would be written without saving anything}';

    /** P1-7: the name the deploy runbook uses; same command. */
    protected $aliases = ['orders:backfill-totals'];

    protected $description = 'Backfill orders.subtotal/tax_total/grand_total from the total accessor derivation. DEPLOY PREREQUISITE for the dashboard SQL aggregates (P1-7): run this in prod BEFORE deploying, or historical revenue under-reports until the nightly run.';

    public function handle(): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'grand_total_minor')) {
            $this->warn('orders.grand_total_minor column not found — run migrations first.');

            return self::SUCCESS;
        }

        $query = Order::query()->with(['items.menu', 'items.variant', 'items.itemExtras.extra']);
        if (! $this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('grand_total_minor')
                ->orWhereHas('items', fn ($i) => $i->whereNull('unit_price_minor')->whereNotNull('item_id')->whereNull('final_minor')));
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $done = 0;
        $written = 0;

        $query->chunkById($chunk, function ($orders) use (&$done, &$written, $dryRun) {
            foreach ($orders as $order) {
                $before = $order->grand_total;
                try {
                    if ($dryRun) {
                        // Same guard + derivation as snapshotTotals(), no write.
                        if ($order->exists && ! $order->items->isEmpty()
                            && (float) ($before ?? PHP_INT_MAX) !== round($order->computeMoney()['grand'], 3)) {
                            $written++;
                        }
                    } else {
                        if (! $order->isFrozenHistory()) {
                            $order->items->each(fn (OrderItem $item) => $item->freezeUnitPrice());
                        }
                        $order->snapshotTotals();
                        if ($order->grand_total !== $before) {
                            $written++;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn("Order #{$order->id}: {$e->getMessage()}");
                }
                $done++;
            }
            $this->info("… processed {$done} orders ({$written} ".($dryRun ? 'would be snapshotted' : 'snapshotted').')');
        });

        $this->info($dryRun
            ? "Dry run. Would snapshot {$written} of {$done} orders — nothing written."
            : "Done. Snapshotted {$written} of {$done} orders.");

        return self::SUCCESS;
    }
}
