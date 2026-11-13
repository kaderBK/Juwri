<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Console\Commands;

use Juwri\Core\Models\Store;
use App\Services\Crm\AutoTagService;
use Illuminate\Console\Command;

/**
 * SYSTEMS-RUN C-P1: nightly recompute of customer system tags across all
 * restaurants (or one via --restaurant). Idempotent.
 */
class CrmAutoTag extends Command
{
    protected $signature = 'crm:auto-tag {--restaurant=}';

    protected $description = 'Recompute customer auto-tags (big spender / regular / lapsed / new).';

    public function handle(AutoTagService $service): int
    {
        $rid = $this->option('restaurant');
        $ids = $rid ? [(int) $rid] : Store::pluck('id')->all();
        $total = 0;
        foreach ($ids as $id) {
            $total += $service->recompute($id);
        }
        $this->info("Auto-tag recompute complete across " . count($ids) . " restaurants ({$total} customers).");
        return self::SUCCESS;
    }
}
