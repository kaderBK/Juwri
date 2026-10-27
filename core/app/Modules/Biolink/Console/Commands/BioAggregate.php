<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;

/**
 * BIOLINK-RUN A6 (G8): reconcile each bio_block's denormalised `clicks_cached`
 * against the authoritative per-day `bio_clicks` sums. recordClick() bumps both
 * live; this nightly pass repairs any drift (a lost increment, a manual DB edit,
 * a restore) so the builder's inline click counts stay honest.
 *
 * Scheduled daily in App\Console\Kernel. Idempotent and side-effect free beyond
 * the clicks_cached repair.
 */
class BioAggregate extends Command
{
    protected $signature = 'bio:aggregate {--post= : Restrict to one store (post id)}';

    protected $description = 'Reconcile bio_blocks.clicks_cached from bio_clicks sums (daily).';

    public function handle(): int
    {
        $storeId = $this->option('post');
        $repaired = 0;

        BioBlock::query()
            ->when($storeId !== null, fn ($q) => $q->where('store_id', (int) $storeId))
            ->orderBy('id')
            ->chunkById(500, function ($blocks) use (&$repaired) {
                foreach ($blocks as $block) {
                    $sum = (int) BioClick::where('block_id', $block->id)->sum('count');
                    if ((int) $block->clicks_cached !== $sum) {
                        $block->clicks_cached = $sum;
                        $block->save();
                        $repaired++;
                    }
                }
            });

        $this->info("bio:aggregate — reconciled {$repaired} block(s).");

        return self::SUCCESS;
    }
}
