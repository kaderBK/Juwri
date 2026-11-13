<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use App\Models\MenuCategory;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Two barcoder-era data defects surface (or vanish) under the new
 * category-driven storefront rendering:
 *
 * 1. CROSS-TENANT: an item whose category belongs to a DIFFERENT store.
 *    Theme paths that render $category->menus show it on the wrong store's
 *    page; restaurant-keyed paths drop it from its own. 11 rows on prod,
 *    all present verbatim in the pre-cutover barcoder dump.
 * 2. ORPHAN: a live item whose category_id no longer resolves (row deleted,
 *    never migrated, or id gap). Category-driven rendering can never reach
 *    it, so the store silently loses visible items. ~5.2k rows / ~400
 *    stores on prod, also all pre-cutover.
 *
 * Read-only by default — repair is a deliberate, owner-run decision.
 * --fix splits the damage in two:
 *  - DUPLICATE orphans (same store, same normalized name as a live item that
 *    sits in a real category — e.g. chef-shafi's $4 كوردن بلو next to his $6.4
 *    one) are SOFT-DELETED: stale price tiers, not content worth keeping.
 *    Reversible for 30 days via the menu trash.
 *  - Everything else is re-homed: a same-named category in the item's OWN
 *    store when one exists, else that store's shared "Uncategorized" category
 *    — created INACTIVE (active='0'), and the storefront skips items whose
 *    category is missing/inactive, so re-homed items never flash onto a
 *    customer menu; the merchant drags them into a real category to publish.
 * Cross-tenant rows always re-home (their placement, not their content, is
 * the defect). Every touched store's storefront menu cache is busted.
 * Idempotent — a clean run reports 0.
 */
class AuditMenuPlacement extends Command
{
    protected $signature = 'menu:audit-placement
                            {--store= : Limit the report/fix to one restaurant id (cross-tenant rows match either side)}
                            {--fix : Re-home the reported items (WRITES)}';

    protected $description = 'Find (and optionally re-home) menu items whose category belongs to another store or to no store';

    /**
     * Duplicate names are judged on collapsed, case-folded text so a stale
     * "كوردن بلو " (trailing space, old price tier) matches its live twin.
     */
    private function normalizeName(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    public function handle(): int
    {
        $storeFilter = $this->option('store') ? (int) $this->option('store') : null;

        $crossTenant = DB::table('items')
            ->join('categories as mc', 'mc.id', '=', 'items.category_id')
            ->whereColumn('mc.store_id', '!=', 'items.store_id')
            ->whereNull('items.deleted_at')
            ->when($storeFilter, fn ($q) => $q->where(function ($q) use ($storeFilter) {
                $q->where('items.store_id', $storeFilter)
                    ->orWhere('mc.store_id', $storeFilter);
            }))
            ->orderBy('items.id')
            ->get(['items.id', 'items.name', 'items.store_id', 'items.category_id', 'mc.name as category_name', 'mc.store_id as category_restaurant_id']);

        $orphans = DB::table('items')
            ->when($storeFilter, fn ($q) => $q->where('items.store_id', $storeFilter))
            // Report what the storefront can actually reach — soft-deleted
            // items are invisible either way, and the Eloquent --fix below
            // (SoftDeletes global scope) would skip them anyway.
            ->whereNull('items.deleted_at')
            ->where(fn ($q) => $q->whereNull('items.category_id')
                ->orWhereNotIn('items.category_id', DB::table('categories')->select('id')))
            ->orderBy('items.id')
            ->get(['items.id', 'items.name', 'items.store_id', 'items.category_id']);

        $this->info(sprintf(
            'cross-tenant items: %d%s',
            $crossTenant->count(),
            $storeFilter ? " (touching store {$storeFilter})" : ''
        ));
        foreach ($crossTenant as $row) {
            $this->line(sprintf(
                '  item %d [%s] store %d -> category %d [%s] of store %d',
                $row->id, $row->name, $row->store_id, $row->category_id, $row->category_name, $row->category_restaurant_id
            ));
        }

        $orphanStores = $orphans->groupBy('store_id');
        $this->info(sprintf(
            'orphan live items: %d across %d stores%s',
            $orphans->count(),
            $orphanStores->count(),
            $storeFilter ? " (store {$storeFilter})" : ''
        ));
        $orphanStores->sortDesc()->take(15)->each(function ($rows, $storeId) {
            $this->line(sprintf('  store %d: %d hidden items', $storeId, $rows->count()));
        });

        if (! $this->option('fix')) {
            $this->line('Read-only pass. Re-run with --fix to re-home these items.');

            return self::SUCCESS;
        }

        if ($crossTenant->isEmpty() && $orphans->isEmpty()) {
            $this->info('Nothing to fix.');

            return self::SUCCESS;
        }

        [$relocated, $deletedDuplicates, $uncategorizedCreated] = DB::transaction(function () use ($crossTenant, $orphans, $storeFilter) {
            $created = 0;

            // Shared "Uncategorized" landing zone per affected store, reused
            // across items so merchants see one bucket to re-file from. INACTIVE:
            // the storefront skips items whose category is inactive, so a
            // re-homed item stays out of the customer menu until the merchant
            // moves it somewhere real (or unhides the bucket).
            $uncategorized = [];
            $ensureUncategorized = function (int $storeId) use (&$uncategorized, &$created): int {
                if (! isset($uncategorized[$storeId])) {
                    $existing = MenuCategory::query()
                        ->where('store_id', $storeId)
                        ->where('name', 'Uncategorized')
                        ->orderBy('id')
                        ->first();
                    if ($existing) {
                        if ((string) $existing->active !== '0') {
                            $existing->update(['active' => '0']);
                        }
                        $uncategorized[$storeId] = $existing->id;
                    } else {
                        $uncategorized[$storeId] = MenuCategory::query()->create([
                            'store_id' => $storeId,
                            'name' => 'Uncategorized',
                            'parent' => null,
                            'position' => 9999,
                            'active' => '0',
                        ])->id;
                        $created++;
                    }
                }

                return $uncategorized[$storeId];
            };

            // Duplicate detection: same store + same normalized name as a live
            // item that actually sits in a real category. The stale copy (old
            // price tier, dead category) is the one nobody can edit — delete it.
            // Query-builder rows (stdClass), not Eloquent hydration: this scans
            // the whole menu table, and models OOM a 128M CLI at 50k+ rows.
            $liveNamesByStore = [];
            foreach (DB::table('items')
                ->join('categories as mc', 'mc.id', '=', 'items.category_id')
                ->whereNull('items.deleted_at')
                ->when($storeFilter, fn ($q) => $q->where('items.store_id', $storeFilter))
                ->get(['items.store_id', 'items.name']) as $live) {
                $liveNamesByStore[$live->store_id][$this->normalizeName($live->name)] = true;
            }

            $duplicateIds = [];
            foreach ($orphans as $orphan) {
                if (isset($liveNamesByStore[$orphan->store_id][$this->normalizeName($orphan->name)])) {
                    $duplicateIds[$orphan->id] = $orphan->store_id;
                }
            }

            // Eloquent delete so SoftDeletes + the cache-bust concern run.
            $deleted = 0;
            foreach (array_chunk(array_keys($duplicateIds), 500) as $chunk) {
                Menu::query()->whereKey($chunk)->get()->each->delete();
                $deleted += count($chunk);
            }

            $survivors = $orphans->reject(fn ($orphan) => isset($duplicateIds[$orphan->id]));

            // item ids grouped by the category they should land in.
            $targets = [];

            // Non-duplicate orphans go to their store's hidden Uncategorized bucket.
            foreach ($survivors as $orphan) {
                $targets[$ensureUncategorized((int) $orphan->store_id)][] = $orphan->id;
            }

            // Cross-tenant: prefer a same-named category in the item's OWN
            // store; fall back to its Uncategorized bucket.
            foreach ($crossTenant as $row) {
                $ownCategoryId = MenuCategory::query()
                    ->where('store_id', $row->store_id)
                    ->where('name', $row->category_name)
                    ->orderBy('id')
                    ->value('id')
                    ?? $ensureUncategorized((int) $row->store_id);
                $targets[$ownCategoryId][] = $row->id;
            }

            $moved = 0;
            foreach ($targets as $categoryId => $itemIds) {
                $moved += Menu::withoutEvents(fn () => Menu::query()
                    ->whereKey($itemIds)
                    ->update(['category_id' => $categoryId]));
            }

            return [$moved, $deleted, $created];
        });

        // Bulk query-builder writes bypass the BustsMenuCache concern — bust
        // every touched store explicitly (soft-deletes above already bust via
        // the concern, but a second bust is free).
        foreach ($orphans->pluck('store_id')->merge($crossTenant->pluck('store_id'))->unique() as $storeId) {
            StorefrontMenuCache::bust((int) $storeId);
        }

        $this->info(sprintf(
            'Fix complete: %d items re-homed, %d duplicate orphans soft-deleted (30-day trash), %d "Uncategorized" buckets created (hidden).',
            $relocated,
            $deletedDuplicates,
            $uncategorizedCreated
        ));

        return self::SUCCESS;
    }
}
