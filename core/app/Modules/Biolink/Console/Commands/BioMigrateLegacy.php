<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Services\BioLinkService;

/**
 * BIOLINK-RUN A3: cut stores over from the legacy `link1..10` post columns to
 * real `bio_blocks` rows, and stamp the `bio_migrated` post_option (G4). Idempotent
 * — a re-run skips already-migrated stores; `--dry-run` reports counts without
 * writing. `--post=ID` restricts to one store.
 *
 * The dual-read in BioLinkService::blocksFor() keeps legacy links visible until a
 * store is migrated, so this command (and the builder's first save) are the only
 * ways the cutover happens — there is no zero-rows cliff.
 */
class BioMigrateLegacy extends Command
{
    protected $signature = 'bio:migrate-legacy {--dry-run : Report what would migrate without writing} {--post= : Restrict to a single store (post id)}';

    protected $description = 'Migrate legacy link1..10 bio fields into typed bio_blocks (idempotent).';

    public function handle(BioLinkService $svc): int
    {
        $dry = (bool) $this->option('dry-run');
        $storeId = $this->option('post');

        $scanned = 0;
        $migratedStores = 0;
        $blocks = 0;

        $query = Store::query()->select('id');
        if ($storeId !== null) {
            $query->where('id', (int) $storeId);
        }

        $query->orderBy('id')->chunkById(200, function ($stores) use ($svc, $dry, &$scanned, &$migratedStores, &$blocks) {
            foreach ($stores as $store) {
                $scanned++;

                if ($svc->isMigrated($store->id)) {
                    continue;
                }

                $would = $svc->legacyLinkCount($store->id);
                if ($would === 0 && ! $dry) {
                    // No legacy links: still flip the flag so the dual-read stops
                    // synthesizing for a store the owner deliberately left empty.
                    $svc->migrateLegacy($store->id);

                    continue;
                }
                if ($would === 0) {
                    continue;
                }

                if ($dry) {
                    $migratedStores++;
                    $blocks += $would;
                    $this->line("  [dry] store {$store->id}: {$would} link block(s) would migrate");

                    continue;
                }

                $created = $svc->migrateLegacy($store->id);
                $migratedStores++;
                $blocks += $created;
            }
        });

        $verb = $dry ? 'would migrate' : 'migrated';
        $this->info("bio:migrate-legacy — scanned {$scanned} store(s); {$verb} {$migratedStores} store(s), {$blocks} link block(s).");

        return self::SUCCESS;
    }
}
