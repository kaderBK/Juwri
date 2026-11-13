<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\HungerStation;

use Juwri\Core\Models\Branch;
use Juwri\Modules\Channels\Models\HsCatalogJob;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P4 — catalog push. Translates local Menu rows into HS catalog updates (price,
 * active, quantity) and records an hs_catalog_jobs row per push. Full product create
 * + export reconcile follow the same client seam (Fake in tests). No live HS call in CI.
 */
class HungerStationCatalogService
{
    /** Build the HS catalog item payload for a menu row (only rows with a mapped sku). */
    public static function translate(Menu $m): ?array
    {
        if (empty($m->hs_sku)) {
            return null;
        }

        return [
            'sku' => $m->hs_sku,
            'price' => (float) $m->price,
            'active' => ! (bool) ($m->is_sold_out ?? false) && (string) $m->active === '1',
            'quantity' => $m->stock ?? null,
        ];
    }

    /** Push availability/price/qty for a branch's mapped menu items. Returns the job. */
    public function pushBranch(int $storeId, int $branchId): HsCatalogJob
    {
        $branch = Branch::find($branchId);
        $items = Menu::where('store_id', $storeId)->whereNotNull('hs_sku')->get()
            ->map(fn ($m) => self::translate($m))->filter()->values()->all();

        $client = HungerStationClientFactory::make($storeId);
        $resp = [];
        try {
            $resp = $client->updateCatalog((string) ($branch->hs_chain_id ?? ''), (string) ($branch->hs_vendor_id ?? ''), $items);
        } catch (\Throwable $e) {
            report($e);
        }

        return HsCatalogJob::create([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'hs_job_id' => $resp['job_id'] ?? null,
            'kind' => 'update',
            'status' => $resp['status'] ?? 'QUEUED',
            'payload' => ['items' => count($items)],
        ]);
    }
}
