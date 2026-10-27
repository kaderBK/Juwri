<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Console\Commands;

use App\Models\Order;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Illuminate\Console\Command;

/**
 * SYSTEMS-RUN A-P1: replay historical paid orders into the GL. Idempotent — a
 * re-run posts nothing new (JournalEngine dedupes per Order+rule_key). Scope to
 * one restaurant with --restaurant, and to a start date with {from}.
 */
class AccountingBackfill extends Command
{
    protected $signature = 'accounting:backfill {from? : YYYY-MM-DD start date} {--restaurant= : restaurant id}';

    protected $description = 'Backfill GL journals from historical paid orders (idempotent).';

    public function handle(): int
    {
        $from = $this->argument('from');
        $rid = $this->option('restaurant');

        $q = Order::where('is_paid', 1);
        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($rid) {
            $q->where('store_id', (int) $rid);
        }

        $posted = 0;
        $q->orderBy('id')->chunkById(200, function ($orders) use (&$posted) {
            foreach ($orders as $order) {
                try {
                    if (OrderJournalPoster::postOrderPaid($order)) {
                        $posted++;
                    }
                } catch (\Throwable $e) {
                    $this->warn("Order #{$order->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Backfill complete. Journals ensured for paid orders (posted/updated: {$posted}).");
        return self::SUCCESS;
    }
}
