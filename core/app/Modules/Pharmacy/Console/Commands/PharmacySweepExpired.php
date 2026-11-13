<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Console\Commands;

use App\Services\Verticals\VerticalRegistry;
use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;

/**
 * rev/multi-vertical Lane F2 — the "optional hide-when-expired" sweep.
 *
 * A store owner opts in via the Compliance settings panel
 * (PharmacyComplianceController::updateSettings); this command is the batch
 * counterpart to the per-item hide-on-save
 * ({@see PharmacyComplianceService::applyHideIfExpired()}) so a catalog
 * still hides items whose expiry_date passes WITHOUT the owner touching that
 * item again. Owner-scheduled (not wired into app/Console/Kernel.php here —
 * see COMPLETION.md "owner owes": add `$schedule->command('pharmacy:sweep-expired')
 * ->daily();` once this ships to prod). Idempotent: re-running only ever
 * deactivates, never reactivates, and a store that never opted in is untouched.
 */
class PharmacySweepExpired extends Command
{
    protected $signature = 'pharmacy:sweep-expired';

    protected $description = 'Deactivate expired items for every store that opted into "hide expired items" (Lane F2).';

    public function handle(VerticalRegistry $registry, PharmacyComplianceService $compliance): int
    {
        $hidden = 0;
        $sweptStores = 0;

        Store::query()
            ->whereNotNull('store_type')
            ->chunkById(200, function ($stores) use (&$hidden, &$sweptStores, $registry, $compliance) {
                foreach ($stores as $store) {
                    if (! $registry->has($store->store_type, 'expiry')) {
                        continue;
                    }
                    $count = $compliance->sweepStore($store);
                    if ($count > 0) {
                        $sweptStores++;
                        $hidden += $count;
                    }
                }
            });

        $this->info("Hid {$hidden} expired item(s) across {$sweptStores} store(s).");

        return self::SUCCESS;
    }
}
