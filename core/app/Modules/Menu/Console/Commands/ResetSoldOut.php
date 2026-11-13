<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use Illuminate\Console\Command;

class ResetSoldOut extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'menu:reset-sold-out {--scheduled : Only reset stores currently in their local midnight hour}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset sold-out menu items to available (is_sold_out = 0)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Scheduled run (hourly): reset each store's sold-out items at ITS OWN
        // local midnight, not one global platform-midnight — a store in another
        // timezone would otherwise have its 86'd items come back mid-service. The
        // 00:xx gate fires a store exactly once per local day. The plain
        // (unscheduled) invocation stays a full reset for manual/ops use.
        // Keep genuinely out-of-stock tracked items 86'd — only a restock should
        // clear those. Blanket-resetting them advertised zero-stock items as
        // available and let the next order oversell. Manual 86s (tracking off, or
        // stock still on hand) reset as before.
        $resettable = fn ($q) => $q->where('is_sold_out', 1)->where(function ($w) {
            $w->where('track_inventory', '!=', 1)
                ->orWhereNull('track_inventory')
                ->orWhere('stock_qty', '>', 0)
                ->orWhereNull('stock_qty');
        });

        if ($this->option('scheduled')) {
            $total = 0;
            $storeIds = \Juwri\Modules\Menu\Models\Menu::where('is_sold_out', 1)->distinct()->pluck('store_id');
            foreach ($storeIds as $rid) {
                if (\Carbon\Carbon::now(store_tz((int) $rid))->format('H') !== '00') {
                    continue;
                }
                $total += $resettable(\Juwri\Modules\Menu\Models\Menu::where('store_id', $rid))->update(['is_sold_out' => 0]);
            }
            $this->info("Reset {$total} sold-out items (stores at local midnight).");

            return;
        }

        $updated = $resettable(\Juwri\Modules\Menu\Models\Menu::query())->update(['is_sold_out' => 0]);
        $this->info("Successfully reset {$updated} sold-out items.");
    }
}
