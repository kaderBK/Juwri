<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\StoreSettings;

/**
 * MC-11 — one-shot, idempotent backfill of orders.currency_code for rows created
 * before MC-10 stamped it (currency_code IS NULL).
 *
 * Each order is stamped with its store's CURRENT currency (store_options
 * `currency_code`), falling back to this install's platform currency —
 * config('settings.currency_code'), read at runtime, never a hardcoded USD.
 *
 * Best-effort caveat (documented): a store that CHANGED its currency in the past
 * has no per-order history to recover, so its historical orders receive today's
 * currency. New orders are stamped correctly at creation (MC-10); this only
 * closes the pre-migration gap.
 *
 * Query-builder only (prefix-safe via DB::table). We snapshot the distinct
 * store-id set FIRST rather than chunking the query, because the chunk filter
 * (currency_code IS NULL) is the very column being written — chunking it would
 * shift the window and skip rows. The distinct set is bounded by store count.
 * Rerunnable: whereNull means a second run is a no-op.
 */
class BackfillOrderCurrencyCodes extends Command
{
    protected $signature = 'currency:backfill-order-codes {--dry : Report only, write nothing}';

    protected $description = 'Backfill orders.currency_code from each store currency (idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $fallback = config('settings.currency_code');

        $before = DB::table('orders')->whereNull('currency_code')->count();
        if ($before === 0) {
            $this->info('Nothing to backfill — every order already has a currency_code.');

            return self::SUCCESS;
        }

        $storeIds = DB::table('orders')
            ->whereNull('currency_code')
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id');

        $updated = 0;
        $skippedStores = 0;

        foreach ($storeIds as $storeId) {
            $currency = StoreSettings::legacy((int) $storeId, 'currency_code');

            $currency = $currency ?: $fallback;

            if (empty($currency)) {
                // No store currency and no platform fallback — leave NULL for the
                // accessor to resolve live rather than guess.
                $skippedStores++;

                continue;
            }

            if ($dry) {
                continue;
            }

            $updated += DB::table('orders')
                ->where('store_id', $storeId)
                ->whereNull('currency_code')
                ->update(['currency_code' => strtoupper((string) $currency)]);
        }

        $remaining = DB::table('orders')->whereNull('currency_code')->count();

        $this->table(
            ['metric', 'value'],
            [
                ['null before', $before],
                ['stores processed', $storeIds->count()],
                ['orders stamped', $dry ? '(dry)' : $updated],
                ['stores with no resolvable currency', $skippedStores],
                ['null remaining', $dry ? $before : $remaining],
            ]
        );

        if (! $dry && $remaining > 0) {
            $this->warn("{$remaining} order(s) still have no currency_code (their store has no currency and no platform fallback is set).");
        } else {
            $this->info(($dry ? '[dry] ' : '').'Backfill complete.');
        }

        return self::SUCCESS;
    }
}
